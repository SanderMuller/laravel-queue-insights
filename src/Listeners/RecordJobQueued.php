<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageClaim;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

/**
 * Records the enqueue timestamp for a job so the worker-side
 * RecordJobProcessing handler can compute wait_ms.
 *
 * Join key: `payload.uuid`, NOT `JobQueued::$id`. Per
 * specs/horizon-inspired-features.md §2.2, `$event->id` is the
 * driver-generated identifier (Redis stream id / DB row PK / SQS
 * message id), which is *not* the same identifier returned by
 * `$event->job->uuid()` later in `RecordJobProcessing`. Decoding the
 * payload and reading `payload.uuid` is the only portable join key.
 */
final class RecordJobQueued
{
    public function handle(JobQueued $event): void
    {
        try {
            $payloadRaw = $event->payload;
            if (! is_string($payloadRaw) || $payloadRaw === '') {
                return;
            }

            $payload = json_decode($payloadRaw, true);
            $uuid = is_array($payload) && isset($payload['uuid']) && is_string($payload['uuid'])
                ? $payload['uuid']
                : null;

            if ($uuid === null || $uuid === '') {
                // Payload-without-uuid path — drivers / Laravel versions that
                // don't stamp a uuid into the payload. The modal renders `—`
                // for that job's wait time.
                return;
            }

            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $redis->command('setex', [
                KeyPrefix::make("pushed:{$uuid}"),
                3600,
                (string) microtime(true),
            ]);

            // Canonicalise — see ConnectionAlias docblock.
            $connection = ConnectionAlias::canonical((string) $event->connectionName);
            $queueKey = CanonicalQueueKey::fromOrDefault((string) $event->queue, $connection);

            $this->writePendingTracking($redis, $event, $uuid, $payload, $connection, $queueKey);
            $this->writeBatchTracking($redis, $uuid, $payload, $connection);
            $this->resolveChainLineage($uuid, $payload, $connection, $queueKey);
            $this->writeScheduleAttribution($redis, $uuid);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobQueued failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Stamp the per-uuid hash + per-queue sorted set so the dashboard can
     * show individual pending and delayed jobs. Driver-agnostic — the data
     * lives entirely in our Redis namespace, so SQS works alongside Redis
     * and database queues.
     *
     * @param  array<array-key, mixed>|null  $payload  Decoded JobQueued payload.
     */
    private function writePendingTracking(RedisConnection $redis, JobQueued $event, string $uuid, ?array $payload, string $connection, string $queueKey): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $displayName = is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
            ? $payload['displayName']
            : '';

        if ($displayName === '') {
            // No class to label the row with — skip rather than show blank entries
            // in the inspector.
            return;
        }

        $queuedAt = Date::now()
            ->getTimestamp();
        $availableAt = $this->resolveAvailableAt($event, $queuedAt);

        $hashKey = KeyPrefix::make("pending:{$uuid}");
        $zsetKey = KeyPrefix::make("pending-zset:{$connection}:{$queueKey}");

        $ttl = Config::int('pending.ttl_seconds', 86400);

        // Six HSET round-trips (one per field) over a single multi-field call so
        // the listener stays portable across phpredis variants — Predis 2.x's
        // hset() helper is 3-arg, and `command('hset', [key, ...flat])` shape
        // diverges between phpredis 4 and 5. The cost is ~6 commands instead of 1;
        // listener fires per-job-queue at producer-side rate, so the absolute
        // throughput hit is negligible vs the maintainability win.
        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null;
        $batchId = is_array($data) && isset($data['batchId']) && is_string($data['batchId']) && $data['batchId'] !== ''
            ? $data['batchId']
            : '';

        $fields = [
            'connection' => $connection,
            'queue' => $queueKey,
            'class' => $displayName,
            'queued_at' => (string) $queuedAt,
            'available_at' => (string) $availableAt,
        ];
        // Only store batch_id when the job is part of a batch — most jobs
        // are not, and the empty placeholder cost ~30 B per hash × every
        // pending row. PendingJobsReader::parseHash treats absent fields
        // the same as empty strings, so the read contract is unchanged.
        if ($batchId !== '') {
            $fields['batch_id'] = $batchId;
        }

        foreach ($fields as $field => $value) {
            $redis->command('hset', [$hashKey, $field, $value]);
        }

        $redis->command('expire', [$hashKey, $ttl]);

        $redis->command('zadd', [$zsetKey, $availableAt, $uuid]);
        $redis->command('expire', [$zsetKey, $ttl]);

        $cap = Config::int('pending.max_per_queue', 10000);
        $count = $redis->command('zcard', [$zsetKey]);
        if (is_int($count) && $count > $cap) {
            // Score-based eviction (resolved Q1) — drops by lowest available_at
            // first. ZREMRANGEBYRANK 0 N where N = (count - cap - 1) trims the
            // overflow; the per-queue zset stays at exactly $cap entries.
            $redis->command('zremrangebyrank', [$zsetKey, 0, $count - $cap - 1]);
        }
    }

    /**
     * Resolve `available_at` (unix seconds) from the JobQueued event's `delay`
     * field. Laravel's event docblock types `delay` as `int|null` — the queue
     * dispatcher coerces DateTimeInterface inputs to int seconds before
     * dispatching the event.
     */
    private function resolveAvailableAt(JobQueued $event, int $queuedAt): int
    {
        $delay = $event->delay;

        return is_int($delay) ? $queuedAt + $delay : $queuedAt;
    }

    /**
     * Stamp batch-tracking keys when the queued payload carries a `batchId`
     * field (set by Laravel's `Bus::batch([...])` flow on every Batchable
     * job). The batchId is plaintext at `data.batchId` — readable even on
     * `ShouldBeEncrypted` jobs whose `data.command` is encrypted.
     *
     * Five keys per batch:
     *   - `qi:batches:index`               aggregate sorted set of batchIds, score = first-seen ts
     *   - `qi:batches:index:{connection}`  per-connection sorted set, first-write-wins via Lua
     *   - `qi:batch:{id}:connection`       string pointer; arbiter for first-write-wins
     *   - `qi:batch:{id}:uuids`            list of uuids in the batch (RPUSH order)
     *   - `qi:batch:uuid:{uuid}`           reverse lookup uuid → batchId (per-row chip)
     *
     * @param  array<array-key, mixed>|null  $payload  Decoded JobQueued payload.
     */
    private function writeBatchTracking(RedisConnection $redis, string $uuid, ?array $payload, string $connectionName): void
    {
        if (! Config::bool('batches.enabled', true)) {
            return;
        }

        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : null;

        $batchId = is_array($data) && isset($data['batchId']) && is_string($data['batchId']) && $data['batchId'] !== ''
            ? $data['batchId']
            : null;

        if ($batchId === null) {
            return;
        }

        $now = Date::now()
            ->getTimestamp();
        $ttl = Config::int('batches.ttl_seconds', 604800);
        $cap = Config::int('batches.max_uuids_per_batch', 5000);

        $indexKey = KeyPrefix::make('batches:index');
        // ZADD NX preserves the first-write-wins score so the head's
        // created-at timestamp survives concurrent JobQueued events.
        // Routed via eval() to dodge phpredis-vs-Predis option-shape divergence
        // — same pattern as RecordJobProcessed::xaddApprox.
        RedisEval::exec(
            $redis,
            "return redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])",
            1,
            $indexKey,
            (string) $now,
            $batchId,
        );
        // EXPIRE on the index would reset whole-key TTL on every ZADD, so
        // old batchIds would never age out. Score-based pruning is cheap
        // (logarithmic) and self-bounding on each enqueue.
        $redis->command('zremrangebyscore', [$indexKey, '-inf', (string) ($now - $ttl)]);

        // Per-connection roster — first-write-wins via Lua so concurrent
        // JobQueued events on different connections for the same batchId
        // can't both claim the batch. The pointer key qi:batch:{id}:connection
        // is the single arbiter; the per-connection ZADD only runs on the
        // winning caller. The same script also stamps the per-uuid
        // connection side-key for BatchScopeFilter (one round-trip vs a
        // separate SETEX). Skip when connectionName is empty — drivers
        // without it can't be dropped into a per-connection roster.
        if ($connectionName !== '') {
            RedisEval::exec(
                $redis,
                LuaScripts::batchClaimConnection(),
                3,
                KeyPrefix::make("batch:{$batchId}:connection"),
                KeyPrefix::make("batches:index:{$connectionName}"),
                KeyPrefix::make("batch-uuid-conn:{$uuid}"),
                $connectionName,
                (string) $ttl,
                (string) $now,
                $batchId,
                (string) ($now - $ttl),
            );
        }

        $uuidsKey = KeyPrefix::make("batch:{$batchId}:uuids");
        $count = $redis->command('llen', [$uuidsKey]);
        if (! is_int($count) || $count < $cap) {
            // Best-effort cap: under heavy concurrent dispatch a few writers
            // can race past the limit before any sees it. ±10 over-cap is
            // acceptable; Bus::findBatch()->totalJobs is the authoritative
            // count.
            $redis->command('rpush', [$uuidsKey, $uuid]);
            $redis->command('expire', [$uuidsKey, $ttl]);
        }

        $redis->command('setex', [
            KeyPrefix::make("batch:uuid:{$uuid}"),
            $ttl,
            $batchId,
        ]);
    }

    /**
     * Try to attribute this newly-queued job to a parent that pushed a claim
     * ticket while entering processing. Per Phase 0 finding: the child's
     * serialized payload doesn't reliably carry a `chainConnection` /
     * `chainQueue` signal under default `Bus::chain([...])->dispatch()` usage,
     * so we attempt RPOP unconditionally — root jobs and non-chain dispatches
     * miss harmlessly.
     *
     * The claim key is built from (connection, queue, displayName,
     * tail-class fingerprint). The "tail" comes from the child's own
     * `chained` property, which Laravel re-serializes after `array_shift`
     * — so the child carries the same tail the parent computed at push
     * time, and the keys collide deterministically.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    private function resolveChainLineage(string $uuid, ?array $payload, string $connection, string $queueKey): void
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return;
        }

        $command = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? ($payload['data']['command'] ?? null)
            : null;

        if (! is_string($command) || $command === '') {
            return;
        }

        $extracted = SerializedCommandReader::extract($command);
        if ($extracted === null) {
            return;
        }

        $childClass = $extracted['class'];
        if (! is_string($childClass) || $childClass === '') {
            return;
        }

        $tailClasses = $this->extractTailClasses($extracted['properties']['chained'] ?? null);
        if ($tailClasses === null) {
            // Malformed chained entry — bail rather than collide on a
            // partially-parsed parent fingerprint.
            return;
        }

        $store = new ChainLineageStore();
        $key = ChainLineageClaim::key($connection, $queueKey, $childClass, $tailClasses);

        $parentUuid = $store->popClaim($key);
        if ($parentUuid === null) {
            return;
        }

        $store->writeLineage(
            $uuid,
            $parentUuid,
            Config::int('chain_lineage.lineage_ttl_seconds', 604800),
        );
    }

    /**
     * Stamp the active scheduled-task frame onto this queued job's
     * metadata so the dashboard can answer "which scheduled task
     * dispatched this job?". No-op when nothing is on the stack
     * (i.e. queued from an HTTP request, queue worker, tinker
     * session, etc.) or when the package's pending-tracking is off
     * (the per-uuid pending hash is the only stable join surface).
     *
     * Two writes per attribution:
     *   - `qi:pending:{uuid}` HSET schedule_task_key + schedule_run_id
     *   - `qi:sched:run-jobs:{runId}` ZADD (score=now, member=uuid)
     *     so `ScheduleReader::jobsDispatchedDuring($runId)` is a
     *     single ZRANGE.
     */
    private function writeScheduleAttribution(RedisConnection $redis, string $uuid): void
    {
        if (! Config::bool('scheduler.enabled', false)) {
            return;
        }

        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $frame = ScheduleContext::current();
        if ($frame === null) {
            return;
        }

        $hashKey = KeyPrefix::make("pending:{$uuid}");
        $redis->command('hset', [$hashKey, 'schedule_task_key', $frame['task_key']]);
        $redis->command('hset', [$hashKey, 'schedule_run_id', $frame['run_id']]);

        $jobsKey = KeyPrefix::make("sched:run-jobs:{$frame['run_id']}");
        $redis->command('zadd', [$jobsKey, Date::now()->getTimestamp(), $uuid]);
        // Cap the per-run job index so a fan-out task (e.g. an importer
        // queueing 100k jobs) can't grow the zset unbounded. Trim the
        // oldest by score; the dashboard surfaces the most-recent slice.
        $cap = Config::int('scheduler.retention.run_jobs_max', 5000);
        $redis->command('zremrangebyrank', [$jobsKey, 0, -($cap + 1)]);
        $redis->command('expire', [$jobsKey, Config::int('scheduler.retention.run_ttl_seconds', 604800)]);
    }

    /**
     * Decode the `chained` property (a list of pre-serialized job bodies)
     * down to a list of class names. Returns null on the first malformed
     * entry — fail closed so the read-side key never collides with a
     * partially-parsed parent fingerprint.
     *
     * @return list<string>|null
     */
    private function extractTailClasses(mixed $chained): ?array
    {
        if ($chained === null) {
            return [];
        }

        if (! is_array($chained)) {
            return null;
        }

        $classes = [];
        foreach ($chained as $entry) {
            if (! is_string($entry) || $entry === '') {
                return null;
            }

            $sub = SerializedCommandReader::extract($entry);
            if ($sub === null || ! is_string($sub['class']) || $sub['class'] === '') {
                return null;
            }

            $classes[] = $sub['class'];
        }

        return $classes;
    }
}
