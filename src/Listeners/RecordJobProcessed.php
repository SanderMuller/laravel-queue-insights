<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\HourBucket;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

final readonly class RecordJobProcessed
{
    public function __construct(
        private ResolveJobClass $resolveJobClass,
        private PayloadSanitizer $sanitizer,
    ) {}

    public function handle(JobProcessed $event): void
    {
        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $connectionName = (string) $event->connectionName;
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::from($queueRaw === '' ? 'default' : $queueRaw);

            $class = $this->resolveJobClass->from($event->job, $connectionName, $queueKey);

            $now = CarbonImmutable::now('UTC');
            $nowTs = $now->getTimestamp();
            $bucket = $now->format('YmdH');
            $isoNow = $now->toIso8601String();

            $durationMs = $this->readAndConsumeStart($redis, $event->job->uuid());

            // Each dual-write group runs as one Lua eval so the aggregate
            // and per-connection variants can't drift on a listener crash.
            // An empty connectionName falls back to aggregate-only writes
            // — otherwise the per-connection key degenerates into a
            // trailing-colon key (e.g. `processed:{class}::{bucket}`).
            $counterDays = max(1, Config::int('retention.processed_counters_days', 7));
            $bucketExpireAt = HourBucket::startTs($bucket) + ($counterDays * 86400);
            $this->writeProcessedCounters($redis, $class, $connectionName, $bucket, $bucketExpireAt);

            if ($durationMs !== null) {
                $this->writeDurationMetrics($redis, $class, $connectionName, $durationMs);
            }

            $this->writeLastRun($redis, $class, $connectionName, $isoNow);

            // qi:class:{uuid} — uuid → class index used by the backward-chain
            // lineage UI to hydrate `parent_uuid` to a class label. Skipped
            // when chain lineage is disabled (no consumer).
            $uuidForClass = $event->job->uuid();
            if (
                Config::bool('chain_lineage.enabled', true)
                && is_string($uuidForClass) && $uuidForClass !== ''
            ) {
                $redis->command('setex', [
                    ParentClassResolver::classKey($uuidForClass),
                    Config::int('chain_lineage.lineage_ttl_seconds', 604800),
                    $class,
                ]);
            }

            // Aggregate roster has no whole-key TTL (pruned 30 d by the
            // snapshot command); per-connection roster bumps EXPIRE on
            // every event so dormant connections fall off cleanly.
            $this->writeClassesRoster($redis, $class, $connectionName, $nowTs);

            // Streams
            $globalStreamId = $this->writeStreams($redis, $event, $class, $connectionName, $queueKey, $durationMs, $isoNow);

            // Batch tracking — index uuid → completed-stream entry id so the
            // batch-detail view can resolve a uuid to the existing completed
            // modal flow (which opens by stream id). Doubles as the
            // chain-lineage uuid → target index — `UuidResolver::resolve`
            // reads this to drive the `↰ From` click-through to the
            // parent's modal. Written unconditionally when chain lineage
            // is on (with the lineage TTL) so click-through works even
            // for hosts that have batches disabled.
            $uuidForBatch = $event->job->uuid();
            $needsTargetIndex = Config::bool('batches.enabled', true)
                || Config::bool('chain_lineage.enabled', true);
            if (
                $globalStreamId !== null
                && $uuidForBatch !== null
                && $uuidForBatch !== ''
                && $needsTargetIndex
            ) {
                $ttl = max(
                    Config::bool('batches.enabled', true) ? Config::int('batches.ttl_seconds', 604800) : 0,
                    Config::bool('chain_lineage.enabled', true) ? Config::int('chain_lineage.lineage_ttl_seconds', 604800) : 0,
                );
                $redis->command('setex', [
                    KeyPrefix::make("uuid-completed:{$uuidForBatch}"),
                    $ttl,
                    $globalStreamId,
                ]);
            }

            // Belt-and-suspenders pending-tracking cleanup. RecordJobProcessing
            // already cleared on the pending → in-flight transition; this is
            // here for the rare case where that listener was missed (worker
            // crash between event dispatch and listener execution).
            $uuidForCleanup = $event->job->uuid();
            if ($uuidForCleanup !== null && $uuidForCleanup !== '' && Config::bool('pending.enabled', true)) {
                $redis->command('del', [KeyPrefix::make("pending:{$uuidForCleanup}")]);
                $redis->command('zrem', [KeyPrefix::make("pending-zset:{$connectionName}:{$queueKey}"), $uuidForCleanup]);
                // In-flight zset entry was added by RecordJobProcessing; drop
                // it now so the dashboard's In-flight group only ever shows
                // jobs that are actually running.
                $redis->command('zrem', [KeyPrefix::make("inflight-zset:{$connectionName}:{$queueKey}"), $uuidForCleanup]);
            }
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function readAndConsumeStart(RedisConnection $redis, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $key = KeyPrefix::make("start:{$uuid}");
        $start = $redis->command('get', [$key]);

        if (! is_numeric($start)) {
            return null;
        }

        $redis->command('del', [$key]);

        $ms = (int) round((microtime(true) - (float) $start) * 1000);

        return max($ms, 0);
    }

    private function writeStreams(
        RedisConnection $redis,
        JobProcessed $event,
        string $class,
        string $connectionName,
        string $queueKey,
        ?int $durationMs,
        string $isoNow,
    ): ?string {
        $baseFields = [
            'class' => $class,
            'connection' => $connectionName,
            'queue' => $queueKey,
            'duration_ms' => (string) ($durationMs ?? ''),
            'attempts' => (string) $event->job->attempts(),
            'processed_at' => $isoNow,
            // uuid lets enrichCompletedRows reverse-route a row to its batch
            // via `qi:batch:uuid:{uuid}` without needing payload capture on.
            'uuid' => (string) ($event->job->uuid() ?? ''),
        ];

        // Forward chain context — JSON-encoded list of every chained job's
        // class + per-link connection/queue, so the modal can offer a click-
        // through detail view without needing payload capture on. Typical
        // size: ~80-300 bytes for a 1-5 link chain.
        $chainJson = $this->encodedChain($event);
        if ($chainJson !== null) {
            $baseFields['chain'] = $chainJson;
        }

        // Backward chain lineage — copy the interim qi:lineage:{uuid} pointer
        // (written by RecordJobQueued on a successful claim pop) into the
        // durable stream row, then forget the interim hash so subsequent reads
        // pick the persisted value off the row directly. The interim hash's
        // 7-day TTL is the safety net if anything in this listener throws
        // before the stamp lands.
        $parentUuid = $this->resolveParentUuid($event);
        if ($parentUuid !== null) {
            $baseFields['parent_uuid'] = $parentUuid;
        }

        if (Config::enum('capture.payloads', CaptureMode::class, CaptureMode::Off)->writesPayloadFields()) {
            foreach ($this->sanitizer->sanitize($event) as $key => $value) {
                $baseFields['payload_' . $key] = $this->encodeStreamValue($value);
            }
        }

        $globalMax = Config::int('retention.completed_stream_max', 10000);
        $perClassMax = Config::int('retention.per_class_stream_max', 1000);
        $perConnMax = Config::int('retention.per_connection_stream_max', 5000);

        $globalKey = KeyPrefix::make('completed');
        $perClassKey = KeyPrefix::make("completed:{$class}");

        $globalStreamId = $this->xaddApprox($redis, $globalKey, $globalMax, $baseFields);

        $perClassFields = $baseFields;
        unset($perClassFields['class']);

        $this->xaddApprox($redis, $perClassKey, $perClassMax, $perClassFields);

        // Skip when connectionName is empty so we don't create a
        // suffix-less `qi:completed:connection:` key.
        if ($connectionName !== '') {
            $perConnKey = KeyPrefix::make("completed:connection:{$connectionName}");
            $this->xaddApprox($redis, $perConnKey, $perConnMax, $baseFields);
        }

        return $globalStreamId;
    }

    private function resolveParentUuid(JobProcessed $event): ?string
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return null;
        }

        $uuid = $event->job->uuid();
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        // Read-only — the interim `qi:lineage:{uuid}` hash is intentionally
        // left to age out via its `lineage_ttl_seconds` TTL (default 7d).
        // Two scenarios depend on this (codex review):
        //   1. A child that failed first, was retried, and now succeeds:
        //      the original failed_jobs row stays in the failed list and
        //      RowEnricher::failed reads `qi:lineage:{uuid}` per render.
        //      Deleting here would orphan that row's parent attribution.
        //   2. Anything throwing between this read and the XADD below —
        //      the lineage hash is the safety net and a delete here would
        //      lose it before any durable record exists.
        return (new ChainLineageStore())->readLineage($uuid);
    }

    private function encodedChain(JobProcessed $event): ?string
    {
        $payload = $event->job->payload();
        $data = $payload['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $chain = SerializedCommandReader::extractChainContext($command);
        if ($chain === null) {
            return null;
        }

        // Persist class/connection/queue only — `properties` per chained job
        // would bloat the stream entry (typical user-data blob is far larger
        // than the routing summary), can carry __PHP_Incomplete_Class refs
        // that don't round-trip cleanly through JSON, and may include PII
        // that the captured-stream retention window outlives. The failed-job
        // modal re-extracts properties at render time from the full
        // serialized payload — that path keeps them.
        $slim = array_map(static fn (array $job): array => [
            'class' => $job['class'],
            'connection' => $job['connection'],
            'queue' => $job['queue'],
        ], $chain['jobs']);

        $encoded = json_encode($slim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    private function encodeStreamValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function xaddApprox(RedisConnection $redis, string $key, int $maxLen, array $fields): ?string
    {
        // phpredis and Predis expose different XADD signatures. Route through eval() so a
        // single code path works on both drivers without a PhpRedisConnection::xAdd fork.
        $result = RedisEval::exec(
            $redis,
            "return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 2))",
            1,
            $key,
            (string) $maxLen,
            ...$this->flattenFields($fields),
        );

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private function flattenFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $out[] = $k;
            $out[] = $v;
        }

        return $out;
    }

    private function writeProcessedCounters(RedisConnection $redis, string $class, string $connectionName, string $bucket, int $bucketExpireAt): void
    {
        if ($connectionName === '') {
            $key = KeyPrefix::make("processed:{$class}:{$bucket}");
            $redis->command('incr', [$key]);
            $redis->command('expireat', [$key, $bucketExpireAt]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::incrPairWithExpire(),
            2,
            KeyPrefix::make("processed:{$class}:{$bucket}"),
            KeyPrefix::make("processed:{$class}:{$connectionName}:{$bucket}"),
            (string) $bucketExpireAt,
        );
    }

    private function writeDurationMetrics(RedisConnection $redis, string $class, string $connectionName, int $durationMs): void
    {
        if ($connectionName === '') {
            $aggDuration = KeyPrefix::classKey('duration', $class);
            $redis->command('hincrby', [$aggDuration, 'count', 1]);
            $redis->command('hincrbyfloat', [$aggDuration, 'sum_ms', (float) $durationMs]);
            RedisEval::exec($redis, LuaScripts::updateMaxDuration(), 1, $aggDuration, (string) $durationMs);
            $redis->command('expire', [$aggDuration, 2592000]);

            $aggSamples = KeyPrefix::classKey('duration:samples', $class);
            $redis->command('rpush', [$aggSamples, (string) $durationMs]);
            $redis->command('ltrim', [$aggSamples, -500, -1]);
            $redis->command('expire', [$aggSamples, 2592000]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::durationPair(),
            2,
            KeyPrefix::classKey('duration', $class),
            KeyPrefix::classKey('duration', $class, $connectionName),
            (string) $durationMs,
            (string) 2592000,
        );

        // Per-connection list capped at the same 500 as the aggregate so a
        // high-volume connection can't crowd out a quiet one in the scoped
        // percentile read.
        RedisEval::exec(
            $redis,
            LuaScripts::samplesPair(),
            2,
            KeyPrefix::classKey('duration:samples', $class),
            KeyPrefix::classKey('duration:samples', $class, $connectionName),
            (string) $durationMs,
            '500',
            (string) 2592000,
        );
    }

    private function writeLastRun(RedisConnection $redis, string $class, string $connectionName, string $isoNow): void
    {
        if ($connectionName === '') {
            $redis->command('setex', [KeyPrefix::classKey('last_run', $class), 2592000, $isoNow]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::setexPair(),
            2,
            KeyPrefix::classKey('last_run', $class),
            KeyPrefix::classKey('last_run', $class, $connectionName),
            (string) 2592000,
            $isoNow,
        );
    }

    private function writeClassesRoster(RedisConnection $redis, string $class, string $connectionName, int $nowTs): void
    {
        if ($connectionName === '') {
            $redis->command('zadd', [KeyPrefix::make('classes'), $nowTs, $class]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::classesRoster(),
            2,
            KeyPrefix::make('classes'),
            KeyPrefix::make("classes:{$connectionName}"),
            (string) $nowTs,
            $class,
            (string) 2592000,
        );
    }
}
