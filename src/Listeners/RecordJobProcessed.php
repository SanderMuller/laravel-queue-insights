<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\HourBucket;
use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ListenerFailureReporter;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

final readonly class RecordJobProcessed
{
    /**
     * 30 days — matches the `classes` / `classes:{connection}` zset
     * retention. A class going dormant 30+ days loses its monotonic
     * counter; next event re-creates at 1 (a counter reset Prometheus
     * `rate()` handles natively). Same boundary as the pre-existing
     * dormant-class handling so observability semantics align.
     */
    private const int MONOTONIC_TTL_SECONDS = 2592000;

    public function __construct(
        private ResolveJobClass $resolveJobClass,
        private PayloadSanitizer $sanitizer,
    ) {}

    public function handle(JobProcessed $event): void
    {
        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            // Canonicalise — see ConnectionAlias docblock.
            $connectionName = ConnectionAlias::canonical((string) $event->connectionName);
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::fromOrDefault($queueRaw, $connectionName);

            $class = $this->resolveJobClass->from($event->job, $connectionName, $queueKey);

            $now = CarbonImmutable::now('UTC');
            $nowTs = $now->getTimestamp();
            $bucket = $now->format('YmdH');
            $isoNow = $now->toIso8601String();

            $uuid = $event->job->uuid();
            $uuid = is_string($uuid) && $uuid !== '' ? $uuid : null;

            // Two round trips: the reads the writes depend on, then every
            // write in one pipeline.
            [$startRaw, $parentUuid] = $this->readStartAndLineage($redis, $uuid);
            $durationMs = is_numeric($startRaw)
                ? max(0, (int) round((microtime(true) - (float) $startRaw) * 1000))
                : null;

            $batch = new RedisBatch($redis);

            if ($uuid !== null && $durationMs !== null) {
                $batch->command('del', [KeyPrefix::make("start:{$uuid}")]);
            }

            // Each dual-write group runs as one Lua eval so the aggregate
            // and per-connection variants can't drift on a listener crash.
            // An empty connectionName falls back to aggregate-only writes
            // — otherwise the per-connection key degenerates into a
            // trailing-colon key (e.g. `processed:{class}::{bucket}`).
            $counterDays = max(1, Config::int('retention.processed_counters_days', 7));
            $bucketExpireAt = HourBucket::startTs($bucket) + ($counterDays * 86400);
            $this->writeProcessedCounters($batch, $class, $connectionName, $bucket, $bucketExpireAt);
            $this->writeProcessedMonotonic($batch, $class, $connectionName);

            if ($durationMs !== null) {
                $this->writeDurationMetrics($batch, $class, $connectionName, $durationMs);
            }

            $this->writeLastRun($batch, $class, $connectionName, $isoNow);

            // qi:class:{uuid} — uuid → class index used by the backward-chain
            // lineage UI to hydrate `parent_uuid` to a class label. Skipped
            // when chain lineage is disabled (no consumer).
            if ($uuid !== null && Config::bool('chain_lineage.enabled', true)) {
                $batch->command('setex', [
                    ParentClassResolver::classKey($uuid),
                    Config::int('chain_lineage.lineage_ttl_seconds', 604800),
                    $class,
                ]);
            }

            // Aggregate roster has no whole-key TTL (pruned 30 d by the
            // snapshot command); per-connection roster bumps EXPIRE on
            // every event so dormant connections fall off cleanly.
            $this->writeClassesRoster($batch, $class, $connectionName, $nowTs);

            $callSite = $this->resolveInitiatorCallSite($uuid);
            $this->writeStreams($batch, $event, $class, $connectionName, $queueKey, $durationMs, $isoNow, $parentUuid, $callSite);

            // Belt-and-suspenders pending-tracking cleanup. RecordJobProcessing
            // already cleared on the pending → in-flight transition; this is
            // here for the rare case where that listener was missed (worker
            // crash between event dispatch and listener execution).
            if ($uuid !== null && Config::bool('pending.enabled', true)) {
                $batch->command('del', [KeyPrefix::make("pending:{$uuid}")]);
                $batch->command('zrem', [KeyPrefix::make("pending-zset:{$connectionName}:{$queueKey}"), $uuid]);
                $batch->command('zrem', [KeyPrefix::make("pending-orphans:{$connectionName}:{$queueKey}"), $uuid]);
                // In-flight zset entry was added by RecordJobProcessing; drop
                // it now so the dashboard's In-flight group only ever shows
                // jobs that are actually running.
                $batch->command('zrem', [KeyPrefix::make("inflight-zset:{$connectionName}:{$queueKey}"), $uuid]);
            }

            $batch->execute();

            // The XADDs have landed, so the call site now lives durably on
            // the completed stream and the interim `qi:initiator:{uuid}` key
            // is redundant. Shrink it to a 60s tail (never DEL — spec §2.2).
            // Placed after execute(), so a failed pipeline keeps the key's
            // full 7d TTL and no attribution is lost.
            if ($callSite !== null && $uuid !== null) {
                (new InitiatorStore())->shortenTtl($uuid);
            }
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'redis_connection' => Config::string('redis_connection', 'default'),
            ]);

            resolve(ListenerFailureReporter::class)->report('RecordJobProcessed', $throwable);
        }
    }

    /**
     * Read `start:{uuid}` (for the duration) and the interim
     * `qi:lineage:{uuid}` parent pointer in one round trip. The lineage read
     * goes to its own connection when `chain_lineage.redis_connection`
     * points elsewhere.
     *
     * The lineage key is read-only here and left to age out via its
     * `lineage_ttl_seconds` TTL (default 7d). Two scenarios depend on this:
     *   1. A child that failed first, was retried, and now succeeds: the
     *      original failed_jobs row stays in the failed list and
     *      RowEnricher::failed reads `qi:lineage:{uuid}` per render.
     *   2. A failure between this read and the XADD — the lineage key is
     *      the safety net until a durable record exists.
     *
     * @return array{mixed, ?string}
     */
    private function readStartAndLineage(RedisConnection $redis, ?string $uuid): array
    {
        if ($uuid === null) {
            return [null, null];
        }

        $lineage = new ChainLineageStore();
        $wantsLineage = Config::bool('chain_lineage.enabled', true);
        $batched = $wantsLineage && $lineage->usesPrimaryConnection();

        $reads = new RedisBatch($redis);
        $reads->command('get', [KeyPrefix::make("start:{$uuid}")]);
        if ($batched) {
            $reads->command('get', [$lineage->lineageKey($uuid)]);
        }

        $results = $reads->execute();

        $parent = match (true) {
            $batched => $results[1] ?? null,
            $wantsLineage => ChainLineageStore::bestEffort('RecordJobProcessed', static fn (): ?string => $lineage->readLineage($uuid)),
            default => null,
        };

        return [$results[0] ?? null, is_string($parent) && $parent !== '' ? $parent : null];
    }

    private function writeStreams(
        RedisBatch $batch,
        JobProcessed $event,
        string $class,
        string $connectionName,
        string $queueKey,
        ?int $durationMs,
        string $isoNow,
        ?string $parentUuid,
        ?string $callSite,
    ): void {
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
        // durable stream row.
        if ($parentUuid !== null) {
            $baseFields['parent_uuid'] = $parentUuid;
        }

        // Initiator origin — restored from the job payload's hidden Context
        // by the worker. Copy it into the durable stream row, conditionally,
        // the same omit-when-empty way as parent_uuid above.
        $origin = $this->resolveInitiatorOrigin();
        if ($origin !== null) {
            $baseFields['origin'] = $origin;
        }

        // Initiator call site — captured queue-side by RecordJobQueued and
        // parked in the interim `qi:initiator:{uuid}` hash (call site can't
        // ride Context). Copied onto the durable row, same omit-when-empty
        // pattern.
        if ($callSite !== null) {
            $baseFields['call_site'] = $callSite;
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

        $this->xaddGlobal($batch, $event, $globalKey, $globalMax, $baseFields);

        $perClassFields = $baseFields;
        unset($perClassFields['class']);

        $this->xaddApprox($batch, $perClassKey, $perClassMax, $perClassFields);

        // Skip when connectionName is empty so we don't create a
        // suffix-less `qi:completed:connection:` key.
        if ($connectionName !== '') {
            $perConnKey = KeyPrefix::make("completed:connection:{$connectionName}");
            $this->xaddApprox($batch, $perConnKey, $perConnMax, $baseFields);
        }
    }

    /**
     * XADD to the global completed stream and, in the same script, index
     * uuid → entry id (`uuid-completed:{uuid}`). The batch-detail view and
     * the chain-lineage `↰ From` click-through follow that index to the
     * completed modal, which opens by stream id. Written when batches or
     * chain lineage is on, with the longer of their TTLs.
     *
     * @param  array<string, string>  $fields
     */
    private function xaddGlobal(RedisBatch $batch, JobProcessed $event, string $key, int $maxLen, array $fields): void
    {
        $uuid = $event->job->uuid();
        $ttl = max(
            Config::bool('batches.enabled', true) ? Config::int('batches.ttl_seconds', 604800) : 0,
            Config::bool('chain_lineage.enabled', true) ? Config::int('chain_lineage.lineage_ttl_seconds', 604800) : 0,
        );

        if (! is_string($uuid) || $uuid === '' || $ttl <= 0) {
            $this->xaddApprox($batch, $key, $maxLen, $fields);

            return;
        }

        $batch->eval(
            "local id = redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 3)) redis.call('SETEX', KEYS[2], ARGV[2], id) return id",
            2,
            $key,
            KeyPrefix::make("uuid-completed:{$uuid}"),
            (string) $maxLen,
            (string) $ttl,
            ...$this->flattenFields($fields),
        );
    }

    /**
     * Read the coarse initiator origin off the worker's restored hidden
     * `Context` (Laravel rehydrates it from the job payload before
     * dispatching JobProcessed). Null when initiator capture is off or no
     * origin was stamped at dispatch time.
     */
    private function resolveInitiatorOrigin(): ?string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_origin', true)) {
            return null;
        }

        $origin = Context::getHidden(Config::string('initiator.context_key', 'qi_origin'));

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Read the dispatch call site out of the interim `qi:initiator:{uuid}`
     * hash (written queue-side by RecordJobQueued). Null when initiator
     * capture or call-site capture is off, the uuid is missing, or no call
     * site was stamped (no application frame resolved at dispatch time).
     */
    private function resolveInitiatorCallSite(?string $uuid): ?string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_call_site', false)) {
            return null;
        }

        if ($uuid === null) {
            return null;
        }

        return (new InitiatorStore())->read($uuid)['call_site'];
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
    private function xaddApprox(RedisBatch $batch, string $key, int $maxLen, array $fields): void
    {
        // phpredis and Predis expose different XADD signatures. Route through eval() so a
        // single code path works on both drivers without a PhpRedisConnection::xAdd fork.
        $batch->eval(
            "return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 2))",
            1,
            $key,
            (string) $maxLen,
            ...$this->flattenFields($fields),
        );
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

    private function writeProcessedCounters(RedisBatch $batch, string $class, string $connectionName, string $bucket, int $bucketExpireAt): void
    {
        if ($connectionName === '') {
            $key = KeyPrefix::make("processed:{$class}:{$bucket}");
            $batch->command('incr', [$key]);
            $batch->command('expireat', [$key, $bucketExpireAt]);

            return;
        }

        $batch->eval(
            LuaScripts::incrPairWithExpire(),
            2,
            KeyPrefix::make("processed:{$class}:{$bucket}"),
            KeyPrefix::make("processed:{$class}:{$connectionName}:{$bucket}"),
            (string) $bucketExpireAt,
        );
    }

    /**
     * Monotonic INCR counters powering the Prometheus exporter's
     * `queue_insights_jobs_processed_total` metric. Spec
     * `internal/specs/prometheus-export.md` §4 — hourly buckets aren't
     * Prometheus-counter-safe (they decrement when retention rotates),
     * so we ship a parallel INCR with a 30-day refreshing EXPIRE.
     *
     * EXPIRE is set per-event (cheap; refreshed on every INCR) so the
     * key naturally ages out for dormant classes. A prune-side DEL
     * would race with a concurrent INCR re-bumping the same class on
     * the roster, breaking monotonicity for an active class. 30 days
     * matches the `classes:{conn}` zset's retention bound.
     */
    private function writeProcessedMonotonic(RedisBatch $batch, string $class, string $connectionName): void
    {
        $aggregate = KeyPrefix::classKey('processed-total', $class);
        $batch->command('incr', [$aggregate]);
        $batch->command('expire', [$aggregate, self::MONOTONIC_TTL_SECONDS]);

        if ($connectionName !== '') {
            $perConnection = KeyPrefix::classKey('processed-total', $class, $connectionName);
            $batch->command('incr', [$perConnection]);
            $batch->command('expire', [$perConnection, self::MONOTONIC_TTL_SECONDS]);
        }
    }

    private function writeDurationMetrics(RedisBatch $batch, string $class, string $connectionName, int $durationMs): void
    {
        // Default kept at 500 so existing installs whose published
        // `queue-insights.php` predates the new nested key don't silently
        // shrink the slow_p95 input window on upgrade. Fresh installs get
        // the same value from the package default — operators wanting the
        // smaller cap (e.g. 200) opt in explicitly via config.
        $samplesCap = max(1, Config::int('retention.duration_samples_cap', 500));

        if ($connectionName === '') {
            $aggDuration = KeyPrefix::classKey('duration', $class);
            $batch->command('hincrby', [$aggDuration, 'count', 1]);
            $batch->command('hincrbyfloat', [$aggDuration, 'sum_ms', (float) $durationMs]);
            $batch->eval(LuaScripts::updateMaxDuration(), 1, $aggDuration, (string) $durationMs);
            $batch->command('expire', [$aggDuration, 2592000]);

            $aggSamples = KeyPrefix::classKey('duration:samples', $class);
            $batch->command('rpush', [$aggSamples, (string) $durationMs]);
            $batch->command('ltrim', [$aggSamples, -$samplesCap, -1]);
            $batch->command('expire', [$aggSamples, 2592000]);

            return;
        }

        $batch->eval(
            LuaScripts::durationPair(),
            2,
            KeyPrefix::classKey('duration', $class),
            KeyPrefix::classKey('duration', $class, $connectionName),
            (string) $durationMs,
            (string) 2592000,
        );

        // Per-connection list capped at the same value as the aggregate so a
        // high-volume connection can't crowd out a quiet one in the scoped
        // percentile read. Cap is driven by `retention.duration_samples_cap`
        // (default 200) — large enough for stable p95 reads, small enough to
        // keep per-class memory bounded under high-throughput tenants.
        $batch->eval(
            LuaScripts::samplesPair(),
            2,
            KeyPrefix::classKey('duration:samples', $class),
            KeyPrefix::classKey('duration:samples', $class, $connectionName),
            (string) $durationMs,
            (string) $samplesCap,
            (string) 2592000,
        );
    }

    private function writeLastRun(RedisBatch $batch, string $class, string $connectionName, string $isoNow): void
    {
        if ($connectionName === '') {
            $batch->command('setex', [KeyPrefix::classKey('last_run', $class), 2592000, $isoNow]);

            return;
        }

        $batch->eval(
            LuaScripts::setexPair(),
            2,
            KeyPrefix::classKey('last_run', $class),
            KeyPrefix::classKey('last_run', $class, $connectionName),
            (string) 2592000,
            $isoNow,
        );
    }

    private function writeClassesRoster(RedisBatch $batch, string $class, string $connectionName, int $nowTs): void
    {
        if ($connectionName === '') {
            $batch->command('zadd', [KeyPrefix::make('classes'), $nowTs, $class]);

            return;
        }

        $batch->eval(
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
