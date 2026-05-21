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
use SanderMuller\QueueInsights\Support\RedisPipeline;
use Throwable;

final class QueueInsightsSnapshotCommand extends Command
{
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

            $this->writeMetric($redis, 'depth', $connection, $canonicalKey, $now, $depth);

            if ($inFlight !== null) {
                $this->writeMetric($redis, 'inflight', $connection, $canonicalKey, $now, $inFlight);
            }

            if ($delayed !== null) {
                $this->writeMetric($redis, 'delayed', $connection, $canonicalKey, $now, $delayed);
            }

            $redis->command('del', [KeyPrefix::make("snapshot:error:{$connection}:{$canonicalKey}")]);

            $this->writeDepthSample($redis, $connection, $canonicalKey, $now, $depth);
            $this->reapStaleTracking($redis, $connection, $canonicalKey, $now);

            $this->dispatcher->dispatchForSnapshot($connection, $canonicalKey, $depth);
        } catch (Throwable $throwable) {
            $errorCanonicalKey = $this->recordError($redis, $connection, $queueInput, $canonicalKey, $throwable);
            $this->dispatcher->dispatchSnapshotError($connection, $errorCanonicalKey);
        }

        unset($driver);
    }

    /**
     * Reap orphaned `pending-zset` / `inflight-zset` members — entries
     * whose backing `pending:{uuid}` hash is gone (TTL'd out) but whose
     * zset member lingered because a cleanup `zrem` was missed. On SQS
     * this is the common case: a job consumed by a worker without the
     * package's listeners, a SIGKILLed worker, or queue-uuid drift.
     * Orphans would otherwise accumulate unbounded — the oldest one
     * perpetually firing `oldest_pending` / `stuck_inflight`.
     *
     * The candidate set is scored below the retention floor
     * (`now - pending.ttl_seconds`): a member that old has, under every
     * current write path, outlived its hash. Orphanhood is then
     * *confirmed* by an actual `EXISTS pending:{uuid}` check rather than
     * inferred from score age alone — so a member that somehow still
     * carries live backing state (a future write path with a longer
     * hash TTL, clock skew) is never wrongly deleted. Runs once per
     * (connection, queue) per snapshot tick.
     */
    private function reapStaleTracking(RedisConnection $redis, string $connection, string $canonicalKey, int $now): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        // Exclusive upper bound `(floor` — strictly older than the
        // retention floor, mirroring the detectors' inclusive `>= floor`
        // live-window lower bound so the two never disagree on an edge member.
        $floor = $now - Config::int('pending.ttl_seconds', 86400);

        foreach (['pending-zset', 'inflight-zset'] as $zset) {
            $key = KeyPrefix::make("{$zset}:{$connection}:{$canonicalKey}");

            $candidates = $redis->command('zrangebyscore', [$key, '-inf', "({$floor}"]);
            $uuids = is_array($candidates) ? array_values(array_filter($candidates, is_string(...))) : [];
            if ($uuids === []) {
                continue;
            }

            // Confirm the backing hash is actually gone before deleting —
            // pipelined so the whole confirmation is one round-trip.
            $exists = RedisPipeline::run($redis, static function (mixed $client) use ($uuids): void {
                foreach ($uuids as $uuid) {
                    $client->exists(KeyPrefix::make("pending:{$uuid}"));
                }
            });

            $orphans = [];
            foreach ($uuids as $i => $uuid) {
                // Reap only on a definitive EXISTS=0 reply. A non-numeric /
                // missing reply (driver hiccup) is treated as "present" so
                // the destructive zrem fails safe — never delete on doubt.
                $flag = $exists[$i] ?? null;
                if (is_numeric($flag) && (int) $flag === 0) {
                    $orphans[] = $uuid;
                }
            }

            if ($orphans !== []) {
                $redis->command('zrem', [$key, ...$orphans]);
            }
        }
    }

    private function writeMetric(
        RedisConnection $redis,
        string $metric,
        string $connection,
        string $canonicalKey,
        int $now,
        int $value,
    ): void {
        $historyKey = KeyPrefix::make("{$metric}:{$connection}:{$canonicalKey}");
        $liveKey = KeyPrefix::make("live:{$metric}:{$connection}:{$canonicalKey}");

        $redis->command('zadd', [$historyKey, $now, (string) $now]);
        $redis->command('expire', [$historyKey, 172800]);
        $redis->command('zremrangebyscore', [$historyKey, 0, $now - 86400]);

        $redis->command('setex', [$liveKey, 90, (string) $value]);
    }

    /**
     * Capped (ts, depth) sample series powering the `backlog_growing`
     * detector. Stored as a ZSET so timestamp ordering is free, and the
     * encoded member `"{ts}:{depth}"` keeps both values addressable
     * without a second key family. Cap to the most-recent 30 samples
     * (~30 minutes at the default cadence) and TTL the whole key at 2 h
     * so an idle queue's series ages out automatically.
     *
     * Always written — the cost is three commands per snapshot per
     * queue. Toggling `alerts.rules.backlog_growing.enabled` without a
     * warm-up window is the operator-friendly default.
     */
    private function writeDepthSample(
        RedisConnection $redis,
        string $connection,
        string $canonicalKey,
        int $now,
        int $depth,
    ): void {
        $key = KeyPrefix::make("samples:depth:{$connection}:{$canonicalKey}");

        $redis->command('zadd', [$key, $now, "{$now}:{$depth}"]);
        $redis->command('zremrangebyrank', [$key, 0, -31]);
        $redis->command('expire', [$key, 7200]);
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
            $redis->command('setex', [
                KeyPrefix::make("snapshot:error:{$connection}:{$errorKey}"),
                600,
                $e->getMessage(),
            ]);

            // Monotonic counter for the Prometheus exporter (spec
            // `internal/specs/prometheus-export.md` §2). Lives outside
            // the 10-min boolean above so the boolean's TTL doesn't
            // fight Prometheus monotonicity.
            $redis->command('incr', [
                KeyPrefix::make("snapshot-errors-total:{$connection}:{$errorKey}"),
            ]);
        } catch (Throwable) {
            // If the insights Redis itself is unreachable we log above and move on.
        }

        return $errorKey;
    }

    private function pruneClasses(RedisConnection $redis): void
    {
        $cutoff = Date::now()->getTimestamp() - 2592000;

        try {
            $redis->command('zremrangebyscore', [
                KeyPrefix::make('classes'),
                0,
                $cutoff,
            ]);

            // Per-connection rosters re-bump their 30d EXPIRE on every
            // event, so dormant connections fall off naturally. The sweep
            // here is belt-and-suspenders for the case where a single
            // long-tail class would otherwise keep an idle roster pinned.
            foreach (ConfiguredConnections::all() as $connection) {
                $redis->command('zremrangebyscore', [
                    KeyPrefix::make("classes:{$connection}"),
                    0,
                    $cutoff,
                ]);
            }

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
