<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Support\SnapshotCadence;
use Throwable;

final class QueueInsightsSnapshotCommand extends Command
{
    private const int MIN_DRAINED_SECONDS = 60;

    protected $signature = 'queue-insights:snapshot';

    protected $description = 'Capture live depth / in-flight / delayed counts for each configured queue.';

    public function __construct(
        private readonly QueueSnapshotDriverFactory $factory,
        private readonly IssueDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $redis = $this->redis();

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            $this->snapshot($redis, $connection, $queue);
        }

        $this->dispatcher->dispatchClassScoped();
        $this->pruneClasses($redis);

        return self::SUCCESS;
    }

    private function snapshot(RedisConnection $redis, string $connection, string $queueInput): void
    {
        $driver = null;
        $canonicalKey = null;

        try {
            $driver = $this->factory->make($connection);
            $canonicalKey = $driver->canonicalKey($queueInput);

            $depth = $driver->depth($queueInput);
            $inFlight = $driver->inFlight($queueInput);
            $delayed = $driver->delayed($queueInput);

            $now = Date::now()->getTimestamp();

            // Two round trips at most: every write plus the reads the
            // cleanup depends on, then the cleanup itself (usually nothing).
            $reads = new RedisBatch($redis);
            $this->queueLiveWrites($reads, $connection, $canonicalKey, $now, $depth, $inFlight, $delayed);
            $cleanup = $this->queueCleanupReads($reads, $connection, $canonicalKey, $now, $depth, $inFlight, $delayed);
            $results = $reads->execute();

            $this->runCleanup($redis, $connection, $canonicalKey, $now, $cleanup, $results);

            $this->dispatcher->dispatchForSnapshot($connection, $canonicalKey, $depth);
        } catch (Throwable $throwable) {
            $errorCanonicalKey = $this->recordError($redis, $connection, $queueInput, $canonicalKey, $throwable);
            $this->dispatcher->dispatchSnapshotError($connection, $errorCanonicalKey);
        }

        unset($driver);
    }

    private function queueLiveWrites(
        RedisBatch $batch,
        string $connection,
        string $canonicalKey,
        int $now,
        int $depth,
        ?int $inFlight,
        ?int $delayed,
    ): void {
        $liveTtl = SnapshotCadence::liveTtlSeconds();

        // Capture time, written with the same TTL as the live keys.
        // The age gauge reads this instead of inferring age from the
        // remaining TTL — an inference that breaks the moment the
        // cadence changes or the expression's gaps are uneven.
        $batch->command('setex', [
            KeyPrefix::make("live:at:{$connection}:{$canonicalKey}"),
            $liveTtl,
            (string) $now,
        ]);

        $this->writeMetric($batch, 'depth', $connection, $canonicalKey, $now, $depth, $liveTtl);

        // An unknown count drops the previous tick's live value, so a
        // stale 0 can't make OldestPendingDetector treat the queue as empty.
        foreach (['inflight' => $inFlight, 'delayed' => $delayed] as $metric => $value) {
            if ($value === null) {
                $batch->command('del', [KeyPrefix::make("live:{$metric}:{$connection}:{$canonicalKey}")]);
            } else {
                $this->writeMetric($batch, $metric, $connection, $canonicalKey, $now, $value, $liveTtl);
            }
        }

        $batch->command('del', [KeyPrefix::make("snapshot:error:{$connection}:{$canonicalKey}")]);

        $this->writeDepthSample($batch, $connection, $canonicalKey, $now, $depth);
    }

    /**
     * Queue the reads the stale-tracking reap and the drained-queue orphan
     * move need, and return where their replies land.
     *
     * @return array{reap: array<string, int>, drainedIndex: ?int, moveIndex: ?int}
     */
    private function queueCleanupReads(
        RedisBatch $batch,
        string $connection,
        string $canonicalKey,
        int $now,
        int $depth,
        ?int $inFlight,
        ?int $delayed,
    ): array {
        $plan = ['reap' => [], 'drainedIndex' => null, 'moveIndex' => null];

        if (! Config::bool('pending.enabled', true)) {
            return $plan;
        }

        // Exclusive upper bound `(floor` — strictly older than the
        // retention floor, mirroring the detectors' inclusive `>= floor`
        // live-window lower bound so the two never disagree on an edge member.
        $floor = $now - Config::int('pending.ttl_seconds', 86400);
        foreach (['pending-zset', 'inflight-zset', 'pending-orphans'] as $zset) {
            $key = KeyPrefix::make("{$zset}:{$connection}:{$canonicalKey}");
            $plan['reap'][$key] = $batch->command('zrangebyscore', [$key, '-inf', "({$floor}"]);
        }

        $drainedKey = KeyPrefix::queueKey('drained-at', $connection, $canonicalKey);
        if ($depth !== 0 || $inFlight !== 0 || $delayed !== 0) {
            $batch->command('del', [$drainedKey]);

            return $plan;
        }

        $grace = max(60, Config::int('alerts.rules.oldest_pending.seconds', 600));
        $plan['drainedIndex'] = $batch->command('get', [$drainedKey]);
        $plan['moveIndex'] = $batch->command('zrangebyscore', [
            KeyPrefix::queueKey('pending-zset', $connection, $canonicalKey),
            '-inf',
            (string) ($now - $grace),
        ]);

        return $plan;
    }

    /**
     * Reap stale `pending-zset` / `inflight-zset` / `pending-orphans` members — entries
     * whose backing `pending:{uuid}` hash is gone (TTL'd out) but whose
     * zset member lingered because a cleanup `zrem` was missed. On SQS
     * this is the common case: a job consumed by a worker without the
     * package's listeners, a SIGKILLed worker, or queue-uuid drift.
     * Orphans would otherwise accumulate unbounded — the oldest one
     * perpetually firing `oldest_pending` / `stuck_inflight`. Candidates are
     * scored below the retention floor; `ReapStaleMembers` then confirms the
     * hash is gone before removing each one.
     *
     * When the broker reports the queue fully drained, every runnable
     * `pending-zset` member is a job whose pending → in-flight move was never
     * recorded (e.g. a Vapor Lambda that died before `JobProcessing` until
     * SQS moved the message to its DLQ). Such an orphan would stay the
     * oldest entry and fire `oldest_pending` once the queue gets new work.
     * It is moved to `pending-orphans`, not deleted: the hash stays, and
     * `MarkInFlight` / the processed and failed cleanup take it out of that
     * set, so a job SQS miscounted still recovers when it runs.
     *
     * Broker counts are approximate (SQS) or read non-atomically, so one
     * empty sample is not enough: the queue must also have been drained on
     * the previous tick, at least MIN_DRAINED_SECONDS earlier (the marker
     * keeps the first drained time and expires with the live keys, so a
     * missed tick resets it). A `null` count never moves anything.
     *
     * @param  array{reap: array<string, int>, drainedIndex: ?int, moveIndex: ?int}  $plan
     * @param  list<mixed>  $results
     */
    private function runCleanup(
        RedisConnection $redis,
        string $connection,
        string $canonicalKey,
        int $now,
        array $plan,
        array $results,
    ): void {
        $batch = new RedisBatch($redis);

        foreach ($plan['reap'] as $key => $index) {
            $uuids = $this->uuids($results[$index] ?? null);
            if ($uuids !== []) {
                $batch->eval(
                    LuaScripts::reapStaleMembers(),
                    1 + count($uuids),
                    $key,
                    ...array_map(static fn (string $uuid): string => KeyPrefix::make("pending:{$uuid}"), $uuids),
                    ...$uuids,
                );
            }
        }

        $moveUuids = [];
        $moveIndex = null;
        if ($plan['drainedIndex'] !== null) {
            $drainedKey = KeyPrefix::queueKey('drained-at', $connection, $canonicalKey);
            $drainedSince = $results[$plan['drainedIndex']] ?? null;

            if (! is_numeric($drainedSince)) {
                $batch->command('setex', [$drainedKey, SnapshotCadence::liveTtlSeconds(), (string) $now]);
            } else {
                $batch->command('expire', [$drainedKey, SnapshotCadence::liveTtlSeconds()]);

                // Two back-to-back runs can read the same stale SQS counts.
                $moveUuids = $now - (int) $drainedSince >= self::MIN_DRAINED_SECONDS && $plan['moveIndex'] !== null
                    ? $this->uuids($results[$plan['moveIndex']] ?? null)
                    : [];
            }
        }

        if ($moveUuids !== []) {
            $moveIndex = $batch->eval(
                LuaScripts::movePendingOrphans(),
                2,
                KeyPrefix::queueKey('pending-zset', $connection, $canonicalKey),
                KeyPrefix::queueKey('pending-orphans', $connection, $canonicalKey),
                (string) Config::int('pending.ttl_seconds', 86400),
                ...$moveUuids,
            );
        }

        $cleanupResults = $batch->execute();

        if ($moveIndex !== null) {
            $moved = $cleanupResults[$moveIndex] ?? null;

            Log::info('queue-insights: moved pending entries to the orphan set on a drained queue', [
                'connection' => $connection,
                'queue' => $canonicalKey,
                'count' => is_numeric($moved) ? (int) $moved : null,
                'uuids' => array_slice($moveUuids, 0, 10),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function uuids(mixed $reply): array
    {
        return is_array($reply) ? array_values(array_filter($reply, is_string(...))) : [];
    }

    private function writeMetric(
        RedisBatch $batch,
        string $metric,
        string $connection,
        string $canonicalKey,
        int $now,
        int $value,
        int $liveTtl,
    ): void {
        $historyKey = KeyPrefix::make("{$metric}:{$connection}:{$canonicalKey}");
        $liveKey = KeyPrefix::make("live:{$metric}:{$connection}:{$canonicalKey}");

        $batch->command('zadd', [$historyKey, $now, (string) $now]);
        $batch->command('expire', [$historyKey, 172800]);
        $batch->command('zremrangebyscore', [$historyKey, '0', (string) ($now - 86400)]);

        $batch->command('setex', [$liveKey, $liveTtl, (string) $value]);
    }

    /**
     * Capped (ts, depth) sample series powering the `backlog_growing`
     * detector. Stored as a ZSET so timestamp ordering is free, and the
     * encoded member `"{ts}:{depth}"` keeps both values addressable
     * without a second key family. Cap to the most-recent 30 samples
     * (~30 minutes at the default cadence) and TTL the whole key at 2 h
     * so an idle queue's series ages out automatically.
     *
     * Always written — toggling `alerts.rules.backlog_growing.enabled`
     * without a warm-up window is the operator-friendly default.
     */
    private function writeDepthSample(
        RedisBatch $batch,
        string $connection,
        string $canonicalKey,
        int $now,
        int $depth,
    ): void {
        $key = KeyPrefix::make("samples:depth:{$connection}:{$canonicalKey}");

        $batch->command('zadd', [$key, $now, "{$now}:{$depth}"]);
        $batch->command('zremrangebyrank', [$key, 0, -31]);
        $batch->command('expire', [$key, 7200]);
    }

    private function recordError(
        RedisConnection $redis,
        string $connection,
        string $queueInput,
        ?string $canonicalKey,
        Throwable $e,
    ): string {
        Log::warning('queue-insights: snapshot failed', [
            'connection' => $connection,
            'queue' => $queueInput,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $errorKey = $canonicalKey ?? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $queueInput) ?? '_');

        try {
            $batch = new RedisBatch($redis);
            $batch->command('setex', [
                KeyPrefix::make("snapshot:error:{$connection}:{$errorKey}"),
                600,
                $e->getMessage(),
            ]);

            // Monotonic counter for the Prometheus exporter (spec
            // `internal/specs/prometheus-export.md` §2). Lives outside
            // the 10-min boolean above so the boolean's TTL doesn't
            // fight Prometheus monotonicity.
            $batch->command('incr', [
                KeyPrefix::make("snapshot-errors-total:{$connection}:{$errorKey}"),
            ]);
            $batch->execute();
        } catch (Throwable) {
            // If the insights Redis itself is unreachable we log above and move on.
        }

        return $errorKey;
    }

    private function pruneClasses(RedisConnection $redis): void
    {
        $cutoff = Date::now()->getTimestamp() - 2592000;

        try {
            $batch = new RedisBatch($redis);
            $batch->command('zremrangebyscore', [
                KeyPrefix::make('classes'),
                '0',
                (string) $cutoff,
            ]);

            // Per-connection rosters re-bump their 30d EXPIRE on every
            // event, so dormant connections fall off naturally. The sweep
            // here is belt-and-suspenders for the case where a single
            // long-tail class would otherwise keep an idle roster pinned.
            foreach (ConfiguredConnections::all() as $connection) {
                $batch->command('zremrangebyscore', [
                    KeyPrefix::make("classes:{$connection}"),
                    '0',
                    (string) $cutoff,
                ]);
            }

            $batch->execute();

            // Prometheus monotonic counters (`processed-total:*`,
            // `failed-total:*`) age out via the per-INCR EXPIRE in
            // `RecordJobProcessed::writeProcessedMonotonic` /
            // `RecordJobFailed::writeFailedMonotonic`, NOT here.
            // A prune-side DEL would race with a concurrent listener
            // INCR re-bumping the same class on the roster, breaking
            // Prometheus monotonicity for an active class.
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: classes prune failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
