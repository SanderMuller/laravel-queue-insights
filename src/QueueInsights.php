<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\DTO\JobClassMetrics;
use SanderMuller\QueueInsights\Support\BatchReader;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\DisplayNamePayloadMatch;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\HourlyBucketReader;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Support\RedisPipeline;
use SanderMuller\QueueInsights\Support\SilencedJobs;
use SanderMuller\QueueInsights\Support\WaitTimeMetrics;
use Throwable;

final class QueueInsights
{
    public function liveDepth(string $connection, string $queue): int
    {
        $value = $this->redis()->command('get', [KeyPrefix::make("live:depth:{$connection}:{$queue}")]);

        return is_string($value) || is_int($value) ? (int) $value : 0;
    }

    public function liveInFlight(string $connection, string $queue): ?int
    {
        $value = $this->redis()->command('get', [KeyPrefix::make("live:inflight:{$connection}:{$queue}")]);

        return is_string($value) || is_int($value) ? (int) $value : null;
    }

    public function liveDelayed(string $connection, string $queue): ?int
    {
        $value = $this->redis()->command('get', [KeyPrefix::make("live:delayed:{$connection}:{$queue}")]);

        return is_string($value) || is_int($value) ? (int) $value : null;
    }

    public function snapshotError(string $connection, string $queue): ?string
    {
        $value = $this->redis()->command('get', [KeyPrefix::make("snapshot:error:{$connection}:{$queue}")]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function lastSnapshotAt(string $connection, string $queue): ?CarbonInterface
    {
        $result = $this->redis()->command('zrange', [
            KeyPrefix::make("depth:{$connection}:{$queue}"),
            -1,
            -1,
            ['withscores' => true],
        ]);

        return $this->decodeLastSnapshotAt($result);
    }

    /**
     * Batch the per-queue snapshot reads (depth + delayed + inflight +
     * snapshot:error + lastSnapshotAt + pendingTrackedCount) into one
     * pipelined Redis round-trip. Used by the dashboard's QueueRowsBuilder
     * so an 8-queue tenant pays 1 RTT per render instead of 48.
     *
     * Includes `pending_tracked_count` only when `pending.enabled` is true —
     * otherwise the field is `null` and callers should treat the inspector
     * as disabled.
     *
     * @param  list<array{connection: string, queue: string}>  $pairs  canonicalised (connection, queue) tuples
     * @return list<array{depth: int, delayed: ?int, inflight: ?int, error: ?string, last_at: ?CarbonInterface, pending_tracked_count: ?int}>
     */
    public function queueRowSnapshots(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $pendingEnabled = Config::bool('pending.enabled', true);

        $results = RedisPipeline::run($this->redis(), static function ($client) use ($pairs, $pendingEnabled): void {
            foreach ($pairs as $pair) {
                $c = $pair['connection'];
                $q = $pair['queue'];
                $client->get(KeyPrefix::make("live:depth:{$c}:{$q}"));
                $client->get(KeyPrefix::make("live:delayed:{$c}:{$q}"));
                $client->get(KeyPrefix::make("live:inflight:{$c}:{$q}"));
                $client->get(KeyPrefix::make("snapshot:error:{$c}:{$q}"));
                $client->zrange(KeyPrefix::make("depth:{$c}:{$q}"), -1, -1, ['withscores' => true]);
                if ($pendingEnabled) {
                    $client->zcard(KeyPrefix::make("pending-zset:{$c}:{$q}"));
                }
            }
        });

        $stride = $pendingEnabled ? 6 : 5;
        $out = [];
        foreach (array_keys($pairs) as $i) {
            $offset = $i * $stride;
            $depthRaw = $results[$offset] ?? null;
            $delayedRaw = $results[$offset + 1] ?? null;
            $inflightRaw = $results[$offset + 2] ?? null;
            $errorRaw = $results[$offset + 3] ?? null;
            $tailRaw = $results[$offset + 4] ?? null;
            $trackedRaw = $pendingEnabled ? ($results[$offset + 5] ?? null) : null;

            $out[] = [
                'depth' => is_string($depthRaw) || is_int($depthRaw) ? (int) $depthRaw : 0,
                'delayed' => is_string($delayedRaw) || is_int($delayedRaw) ? (int) $delayedRaw : null,
                'inflight' => is_string($inflightRaw) || is_int($inflightRaw) ? (int) $inflightRaw : null,
                'error' => is_string($errorRaw) && $errorRaw !== '' ? $errorRaw : null,
                'last_at' => $this->decodeLastSnapshotAt($tailRaw),
                'pending_tracked_count' => $pendingEnabled && is_numeric($trackedRaw) ? (int) $trackedRaw : null,
            ];
        }

        return $out;
    }

    private function decodeLastSnapshotAt(mixed $tail): ?CarbonInterface
    {
        if (! is_array($tail) || $tail === []) {
            return null;
        }

        $score = array_values($tail)[0] ?? null;

        return is_numeric($score) ? Date::createFromTimestamp((int) $score) : null;
    }

    /**
     * @return array<int, int> [timestamp => count]
     */
    public function depthHistory(string $connection, string $queue): array
    {
        return $this->history("depth:{$connection}:{$queue}");
    }

    /**
     * @return array<int, int>
     */
    public function inFlightHistory(string $connection, string $queue): array
    {
        return $this->history("inflight:{$connection}:{$queue}");
    }

    /**
     * @return array<int, int>
     */
    public function delayedHistory(string $connection, string $queue): array
    {
        return $this->history("delayed:{$connection}:{$queue}");
    }

    /**
     * @return list<array{connection: string, queue: string}>
     */
    public function configuredQueues(?string $scopeConnection = null): array
    {
        $out = [];

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

            if ($scopeConnection !== null && $connection !== $scopeConnection) {
                continue;
            }

            $out[] = [
                'connection' => $connection,
                'queue' => $queue,
            ];
        }

        return $out;
    }

    /**
     * @return list<string> Class names ordered by last seen (newest first).
     *                     When `$connection` is non-null, returns only classes
     *                     that have run on that connection within the per-
     *                     connection roster's TTL (30d).
     */
    public function jobClasses(?string $connection = null): array
    {
        $key = $connection === null
            ? KeyPrefix::make('classes')
            : KeyPrefix::make("classes:{$connection}");

        $result = $this->redis()->command('zrevrange', [$key, 0, -1]);

        if (! is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $class) {
            if (is_string($class)) {
                $out[] = $class;
            }
        }

        return $out;
    }

    public function classMetrics(string $class, ?string $connection = null): JobClassMetrics
    {
        $redis = $this->redis();

        $bucketSuffix = $connection === null ? "{$class}" : "{$class}:{$connection}";
        $processed = $this->sumCounters($redis, $this->hourlyBucketKeys("processed:{$bucketSuffix}", 24));
        $failed = $this->sumCounters($redis, $this->hourlyBucketKeys("failed:{$bucketSuffix}", 24));

        // Single HMGET — three HGETs on the same key were a hot-path waste.
        // phpredis returns an associative array keyed by field; Predis
        // returns a positional list. `array_values` normalises both into
        // the input-field order so the positional access below works for
        // both client drivers.
        $fields = $redis->command('hmget', [
            KeyPrefix::classKey('duration', $class, $connection),
            ['count', 'sum_ms', 'max_ms'],
        ]);
        $values = is_array($fields) ? array_values($fields) : [];
        $count = is_numeric($values[0] ?? null) ? (int) $values[0] : 0;
        $sumMs = is_numeric($values[1] ?? null) ? (float) $values[1] : 0.0;
        $maxMs = is_numeric($values[2] ?? null) ? (int) $values[2] : null;

        $lastRunRaw = $redis->command('get', [KeyPrefix::classKey('last_run', $class, $connection)]);
        $lastRunAt = is_string($lastRunRaw) && $lastRunRaw !== '' ? Date::parse($lastRunRaw) : null;

        return new JobClassMetrics(
            class: $class,
            processed24h: $processed,
            failed24h: $failed,
            avgDurationMs: $count > 0 ? $sumMs / $count : null,
            maxDurationMs: $maxMs,
            p95DurationMs: $this->p95DurationMs($class, $connection),
            lastRunAt: $lastRunAt,
        );
    }

    /**
     * Per-queue wait-time percentiles (ms). The `wait:{connection}:{queue}`
     * ZSET stores uuids ordered by insertion timestamp (recency), not by
     * wait_ms — that lets the trim policy (`ZREMRANGEBYRANK 0 -1001`) drop
     * the oldest 1000+ rather than the fastest. Percentile read joins each
     * recent uuid back to its `wait:{uuid}` sample via MGET.
     *
     * Returns `null` for both fields when fewer than 10 samples are
     * recoverable (too few for the metric to be meaningful — render `—`
     * in the UI).
     *
     * @return array{p50: ?int, p95: ?int}
     */
    public function queueWaitPercentiles(string $connection, string $queue): array
    {
        return WaitTimeMetrics::percentiles($connection, $queue);
    }

    /**
     * Pending jobs (available_at <= now) across every configured queue,
     * sorted by `available_at` ascending — the jobs that have been
     * runnable longest come first. That ordering matches the pending zset
     * score so we don't pay a per-uuid hash hydration just to re-sort,
     * and it's what ops cares about for a "what's been waiting" view.
     *
     * Two-stage to keep the 10s poll cheap:
     *   1. Per queue, ZRANGEBYSCORE WITHSCORES (limit = global limit) —
     *      no hash reads. N round-trips, returns uuid → score tuples.
     *   2. Globally sort, slice to $limit, then HGETALL only the
     *      survivors. Worst-case HGETALL count is bounded by $limit (50),
     *      independent of how many queues are backed up.
     *
     * Caps total at 200 so a misuse can't flood the dashboard.
     *
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public function allPendingJobs(int $limit = 50): array
    {
        return PendingJobsReader::allPending($this->configuredQueues(), $limit);
    }

    /**
     * Delayed jobs (available_at > now) across every configured queue,
     * sorted soonest-first. Mirror of `allPendingJobs` for the dashboard's
     * top-level Delayed sub-section.
     *
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public function allDelayedJobs(int $limit = 50): array
    {
        return PendingJobsReader::allDelayed($this->configuredQueues(), $limit);
    }

    /**
     * In-flight jobs (currently being processed by workers) across every
     * configured queue, sorted longest-running first. Drives the dashboard's
     * top-level In-flight sub-group above Pending now.
     *
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public function allInFlightJobs(int $limit = 50): array
    {
        return PendingJobsReader::allInFlight($this->configuredQueues(), $limit);
    }

    /**
     * Pending jobs (available_at <= now) for a queue, oldest-first.
     *
     * Reads the `pending-zset:{conn}:{canonical-queue}` sorted set written
     * by `RecordJobQueued` and hydrates each uuid with its `pending:{uuid}`
     * hash. Driver-agnostic — works for Redis, Database, AND SQS because
     * the data lives entirely in our Redis namespace.
     *
     * @return list<array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public function pendingJobs(string $connection, string $queue, int $limit = 50): array
    {
        return PendingJobsReader::readZset(
            connection: $connection,
            queue: $queue,
            min: '-inf',
            max: (string) Date::now()->getTimestamp(),
            limit: $limit,
        );
    }

    /**
     * Delayed jobs (available_at > now) for a queue, soonest-first.
     *
     * @return list<array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public function delayedJobs(string $connection, string $queue, int $limit = 50): array
    {
        // ZRANGEBYSCORE uses '(' to make the lower bound exclusive — pending
        // jobs whose available_at == now go to `pendingJobs`, not here.
        return PendingJobsReader::readZset(
            $connection,
            $queue,
            '(' . Date::now()->getTimestamp(),
            '+inf',
            $limit,
        );
    }

    /**
     * `ZCARD` of the per-queue pending tracking set. Used by the dashboard to
     * compute the drift gap against `liveDepth() + liveDelayed()` — when the
     * tracked count diverges from the snapshot, the lists below are a sample,
     * not a complete enumeration.
     */
    public function pendingTrackedCount(string $connection, string $queue): int
    {
        return PendingJobsReader::trackedCount($connection, $queue);
    }

    /**
     * Single-uuid hydration — fallback for the dashboard's pending modal when
     * the requested uuid sits outside the capped 50-row aggregate windows.
     * Returns the same row shape as `allPendingJobs()`/`allInFlightJobs()`.
     *
     * @return array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}|null
     */
    public function findPendingByUuid(string $uuid): ?array
    {
        return PendingJobsReader::findByUuid($uuid);
    }

    /**
     * Recent batches (newest first) joined to Laravel's authoritative
     * `Bus::findBatch()` for live counts. Drives the dashboard's Batches
     * section.
     *
     * @return list<array{
     *   id: string,
     *   name: ?string,
     *   total_jobs: int,
     *   pending_jobs: int,
     *   processed_jobs: int,
     *   failed_jobs: int,
     *   progress: int,
     *   created_at: ?CarbonInterface,
     *   finished_at: ?CarbonInterface,
     *   cancelled_at: ?CarbonInterface,
     * }>
     */
    public function recentBatches(int $limit = 50, ?string $connection = null): array
    {
        return BatchReader::recentBatches($limit, $connection);
    }

    /**
     * Single-batch view with a uuid list, for the Batches-section expand.
     *
     * @return array{
     *   id: string,
     *   name: ?string,
     *   total_jobs: int,
     *   pending_jobs: int,
     *   processed_jobs: int,
     *   failed_jobs: int,
     *   progress: int,
     *   created_at: ?CarbonInterface,
     *   finished_at: ?CarbonInterface,
     *   cancelled_at: ?CarbonInterface,
     *   uuids: list<string>,
     * }|null
     */
    public function batchDetail(string $batchId, ?string $connection = null): ?array
    {
        return BatchReader::batchDetail($batchId, $connection);
    }

    /**
     * Per-job wait time in ms — the value `RecordJobProcessing` derived
     * from `pushed:{uuid}` and stored as `wait:{uuid}`. Returns `null`
     * when the sample is missing (legacy job, custom driver, or queued
     * before the `JobQueued` listener was wired).
     */
    public function jobWaitMs(string $uuid): ?int
    {
        if ($uuid === '') {
            return null;
        }

        $value = $this->redis()->command('get', [KeyPrefix::make("wait:{$uuid}")]);

        return is_numeric($value) ? (int) $value : null;
    }

    public function p95DurationMs(string $class, ?string $connection = null): ?int
    {
        $key = KeyPrefix::classKey('duration:samples', $class, $connection);

        $samples = $this->redis()->command('lrange', [$key, 0, -1]);

        if (! is_array($samples) || $samples === []) {
            return null;
        }

        $nums = [];
        foreach ($samples as $s) {
            if (is_numeric($s)) {
                $nums[] = (int) $s;
            }
        }

        if ($nums === []) {
            return null;
        }

        sort($nums);

        $idx = (int) ceil(0.95 * count($nums)) - 1;
        $idx = max(0, min(count($nums) - 1, $idx));

        return $nums[$idx];
    }

    /**
     * @return list<array<string, string>>
     */
    public function recentCompleted(int $limit = 100, ?string $class = null, ?string $connection = null): array
    {
        // Routing matrix:
        //   class=null,  connection=null  → qi:completed (aggregate)
        //   class=set,   connection=null  → qi:completed:{class}
        //   class=null,  connection=set   → qi:completed:connection:{connection}
        //   class=set,   connection=set   → qi:completed:{class} then post-filter on the row's `connection` field
        $hasClass = $class !== null && $class !== '';
        $hasConnection = $connection !== null && $connection !== '';

        [$key, $effectiveLimit] = $this->resolveCompletedStreamKey($limit, $class, $connection, $hasClass, $hasConnection);

        // 4-arg form works on both phpredis (native) and Predis (auto-injects COUNT token via XRANGE::setArguments).
        $entries = $this->redis()->command('xrevrange', [$key, '+', '-', $effectiveLimit]);

        if (! is_array($entries)) {
            return [];
        }

        $postFilterConnection = $hasClass && $hasConnection ? $connection : null;

        $out = [];
        foreach ($entries as $id => $fields) {
            $row = $this->normaliseStreamRow((string) $id, $fields);
            if ($row === null) {
                continue;
            }

            // Class+connection drilldown — drop rows whose `connection`
            // field doesn't match. Per-class stream entries carry the
            // field; a missing field falls through and is dropped.
            if ($postFilterConnection !== null && ($row['connection'] ?? null) !== $postFilterConnection) {
                continue;
            }

            $out[] = $row;

            // Stop once the class+scope branch has $limit matches — it
            // read the full class-stream cap upfront for the worst-case
            // post-filter, but most renders only need $limit.
            if ($postFilterConnection !== null && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveCompletedStreamKey(
        int $limit,
        ?string $class,
        ?string $connection,
        bool $hasClass,
        bool $hasConnection,
    ): array {
        if ($hasClass) {
            // Class+scope reads the full class-stream cap so the
            // post-filter has the widest possible window — otherwise a
            // class hot on a foreign connection drops scoped rows out of
            // the small `min($limit, 1000)` slice. Bounded by
            // `retention.per_class_stream_max` (default 1000).
            $effectiveLimit = $hasConnection
                ? Config::int('retention.per_class_stream_max', 1000)
                : min($limit, 1000);

            return [KeyPrefix::make("completed:{$class}"), $effectiveLimit];
        }

        if ($hasConnection) {
            return [KeyPrefix::make("completed:connection:{$connection}"), min($limit, 10000)];
        }

        return [KeyPrefix::make('completed'), min($limit, 10000)];
    }

    /**
     * @return array<string, string>|null
     */
    private function normaliseStreamRow(string $id, mixed $fields): ?array
    {
        if (! is_array($fields)) {
            return null;
        }

        $row = ['_id' => $id];
        foreach ($fields as $k => $v) {
            if (! is_string($v) && ! is_int($v) && ! is_float($v) && ! is_bool($v)) {
                continue;
            }

            $row[(string) $k] = (string) $v;
        }

        return $row;
    }

    /**
     * Per-hour processed + failed throughput across all classes, oldest first.
     * When `$connection` is non-null, throughput is restricted to that
     * connection.
     *
     * @return list<array{timestamp: int, processed: int, failed: int}>
     */
    public function hourlyThroughput(int $hours = 24, ?string $connection = null): array
    {
        [$timestamps, $bucketIndex] = HourlyBucketReader::buildTimeline($hours);
        $classes = $this->jobClasses($connection);
        $redis = $this->redis();

        $processedCounts = HourlyBucketReader::sumPerBucket($redis, $classes, $bucketIndex, 'processed', $connection);

        // Silenced classes drop out of the failed-bucket fan-out so the
        // headline mirrors the visible Failed list. Processed stays exact.
        $silenced = resolve(SilencedJobs::class);
        $failedClasses = array_values(array_filter(
            $classes,
            static fn (string $c): bool => ! $silenced->isSilenced($c),
        ));
        $failedCounts = HourlyBucketReader::sumPerBucket($redis, $failedClasses, $bucketIndex, 'failed', $connection);

        $timeline = [];
        for ($i = 0; $i < $hours; ++$i) {
            $timeline[] = [
                'timestamp' => $timestamps[$i],
                'processed' => $processedCounts[$i],
                'failed' => $failedCounts[$i],
            ];
        }

        return $timeline;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function recentFailed(int $limit = 100, ?FailedJobFilters $filters = null): array
    {
        $filters ??= new FailedJobFilters();

        try {
            $query = self::applyFailedJobFilters(
                DB::table('failed_jobs')->orderByDesc('id')->limit($limit),
                $filters,
            );

            $rows = $query->get()->toArray();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    /**
     * Apply the failed-jobs filter set to a query builder. Shared between
     * `recentFailed` (table read) and the bulk-retry uuid collector — both
     * must produce the same match set (Resolved Q #5/#7).
     */
    public static function applyFailedJobFilters(Builder $query, FailedJobFilters $filters): Builder
    {
        if ($filters->connection !== '') {
            $query->where('connection', $filters->connection);
        }

        if ($filters->queue !== '') {
            $query->where('queue', $filters->queue);
        }

        if ($filters->class !== '') {
            $pattern = DisplayNamePayloadMatch::pattern($filters->class);
            if ($pattern !== null) {
                $query->whereRaw('LOWER(payload) LIKE ? ESCAPE ?', $pattern);
            }
        }

        if ($filters->from !== '') {
            $query->where('failed_at', '>=', $filters->from . ' 00:00:00');
        }

        if ($filters->to !== '') {
            $query->where('failed_at', '<=', $filters->to . ' 23:59:59');
        }

        if (! $filters->includeSilenced) {
            resolve(SilencedJobs::class)->appendExclusion($query);
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    private function history(string $suffix): array
    {
        $now = Date::now()->getTimestamp();
        $since = $now - 86400;

        $result = $this->redis()->command('zrangebyscore', [
            KeyPrefix::make($suffix),
            $since,
            $now,
            ['withscores' => true],
        ]);

        if (! is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $count => $score) {
            if (! is_numeric($score)) {
                continue;
            }

            $out[(int) $score] = is_numeric($count) ? (int) $count : 0;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function hourlyBucketKeys(string $suffix, int $hours): array
    {
        $now = Date::now('UTC');
        $keys = [];

        for ($i = 0; $i < $hours; ++$i) {
            $bucket = $now->copy()->subHours($i)->format('YmdH');
            $keys[] = KeyPrefix::make("{$suffix}:{$bucket}");
        }

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     */
    private function sumCounters(RedisConnection $redis, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        // phpredis mGet() takes a single array arg; Predis auto-unwraps a single-array arg
        // via Command::normalizeArguments. Pass `[$keys]` to satisfy both.
        $values = $redis->command('mget', [$keys]);

        if (! is_array($values)) {
            return 0;
        }

        $total = 0;
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $total += (int) $v;
            }
        }

        return $total;
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
