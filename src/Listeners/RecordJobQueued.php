<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;
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

            $this->writePendingTracking($redis, $event, $uuid, $payload);
            $this->writeBatchTracking($redis, $uuid, $payload);
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
    private function writePendingTracking(RedisConnection $redis, JobQueued $event, string $uuid, ?array $payload): void
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

        $connection = (string) $event->connectionName;
        $queueRaw = (string) $event->queue;
        // CanonicalQueueKey collapses driver-specific raw queue values (SQS
        // URLs vs plain names) so the zset key written here matches the
        // cleanup zset key read in RecordJobProcessing / Processed / Failed,
        // where the queue arrives as `$event->job->getQueue()` (a name, not
        // a URL).
        $queueKey = $queueRaw === '' ? 'default' : CanonicalQueueKey::from($queueRaw);

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

        foreach ([
            'connection' => $connection,
            'queue' => $queueKey,
            'class' => $displayName,
            'queued_at' => (string) $queuedAt,
            'available_at' => (string) $availableAt,
            // Empty string when the job isn't part of a batch — keeps the field
            // shape stable so `PendingJobsReader::readHash` can read it
            // unconditionally without an HEXISTS round-trip.
            'batch_id' => $batchId,
        ] as $field => $value) {
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
     * Three keys per batch:
     *   - `qi:batches:index`       sorted set of batchIds, score = first-seen ts
     *   - `qi:batch:{id}:uuids`    list of uuids in the batch (RPUSH order)
     *   - `qi:batch:uuid:{uuid}`   reverse lookup uuid → batchId (per-row chip)
     *
     * @param  array<array-key, mixed>|null  $payload  Decoded JobQueued payload.
     */
    private function writeBatchTracking(RedisConnection $redis, string $uuid, ?array $payload): void
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
}
