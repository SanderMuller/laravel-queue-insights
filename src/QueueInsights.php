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
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\HourlyBucketReader;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
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

        if (! is_array($result) || $result === []) {
            return null;
        }

        $score = array_values($result)[0] ?? null;

        if (! is_numeric($score)) {
            return null;
        }

        return Date::createFromTimestamp((int) $score);
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
        $fields = $redis->command('hmget', [
            KeyPrefix::classKey('duration', $class, $connection),
            ['count', 'sum_ms', 'max_ms'],
        ]);
        $count = is_array($fields) && is_numeric($fields[0] ?? null) ? (int) $fields[0] : 0;
        $sumMs = is_array($fields) && is_numeric($fields[1] ?? null) ? (float) $fields[1] : 0.0;
        $maxMs = is_array($fields) && is_numeric($fields[2] ?? null) ? (int) $fields[2] : null;

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
    public function recentBatches(int $limit = 50): array
    {
        return BatchReader::recentBatches($limit);
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
    public function batchDetail(string $batchId): ?array
    {
        return BatchReader::batchDetail($batchId);
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
    public function recentCompleted(int $limit = 100, ?string $class = null): array
    {
        $key = $class === null
            ? KeyPrefix::make('completed')
            : KeyPrefix::make("completed:{$class}");

        $effectiveLimit = $class === null ? min($limit, 10000) : min($limit, 1000);

        // 4-arg form works on both phpredis (native) and Predis (auto-injects COUNT token via XRANGE::setArguments).
        $entries = $this->redis()->command('xrevrange', [$key, '+', '-', $effectiveLimit]);

        if (! is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $id => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $row = ['_id' => (string) $id];
            foreach ($fields as $k => $v) {
                if (! is_string($v) && ! is_int($v) && ! is_float($v) && ! is_bool($v)) {
                    continue;
                }

                $row[(string) $k] = (string) $v;
            }

            $out[] = $row;
        }

        return $out;
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
        $failedCounts = HourlyBucketReader::sumPerBucket($redis, $classes, $bucketIndex, 'failed', $connection);

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
            // Anchored substring LIKE against the raw JSON payload.
            //
            // The class FQCN sits in the JSON column as `"displayName":"App\\Jobs\\Foo"`
            // — `\` JSON-escaped to `\\`. Match that exact byte sequence by
            // re-running the FQCN through json_encode, which produces the
            // same `\\` form, then stripping the outer quotes.
            //
            // Use `ESCAPE '|'` so the LIKE engine treats '|' as the escape
            // char instead of the default '\'. Without it, MySQL's default
            // backslash-as-escape rule consumes the literal `\\` in the
            // pattern back to a single `\`, which never matches the JSON
            // column's `\\` (bug report — class filter returned 0 results on
            // MySQL even when matches existed). PostgreSQL ignores `\` in
            // LIKE by default; SQLite likewise — `ESCAPE '|'` is portable
            // across all three.
            //
            // Wrap both sides in `LOWER()` so a deep-linked filter with
            // mismatched casing (e.g. `?fk=app\jobs\foo`) still matches the
            // canonical-cased payload. Without normalisation PostgreSQL's
            // case-sensitive LIKE would silently miss while MySQL/SQLite
            // matched, producing DB-dependent behaviour for URL-bound
            // input (codex review). ASCII-only class names sidestep the
            // locale-aware `LOWER()` differences across DB engines.
            $encoded = json_encode($filters->class, JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $needleClass = strtolower(trim($encoded, '"'));
                // Escape LIKE wildcards (and the ESCAPE char itself) in user
                // input so a class name containing `%` / `_` / `|` doesn't
                // smuggle a wildcard match.
                $needleClass = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $needleClass);
                $query->whereRaw('LOWER(payload) LIKE ? ESCAPE ?', ['%"displayname":"' . $needleClass . '%', '|']);
            }
        }

        if ($filters->from !== '') {
            $query->where('failed_at', '>=', $filters->from . ' 00:00:00');
        }

        if ($filters->to !== '') {
            $query->where('failed_at', '<=', $filters->to . ' 23:59:59');
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
