<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\DTO\JobClassMetrics;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\KeyPrefix;
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
    public function configuredQueues(): array
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

            $out[] = [
                'connection' => $connection,
                'queue' => $queue,
            ];
        }

        return $out;
    }

    /**
     * @return list<string> Class names ordered by last seen (newest first).
     */
    public function jobClasses(): array
    {
        $result = $this->redis()->command('zrevrange', [
            KeyPrefix::make('classes'),
            0,
            -1,
        ]);

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

    public function classMetrics(string $class): JobClassMetrics
    {
        $redis = $this->redis();

        $processedKeys = $this->hourlyBucketKeys("processed:{$class}", 24);
        $failedKeys = $this->hourlyBucketKeys("failed:{$class}", 24);

        $processed = $this->sumCounters($redis, $processedKeys);
        $failed = $this->sumCounters($redis, $failedKeys);

        $durationKey = KeyPrefix::make("duration:{$class}");

        $countRaw = $redis->command('hget', [$durationKey, 'count']);
        $count = is_numeric($countRaw) ? (int) $countRaw : 0;

        $sumRaw = $redis->command('hget', [$durationKey, 'sum_ms']);
        $sumMs = is_numeric($sumRaw) ? (float) $sumRaw : 0.0;

        $maxMsRaw = $redis->command('hget', [$durationKey, 'max_ms']);
        $maxMs = is_numeric($maxMsRaw) ? (int) $maxMsRaw : null;

        $avgMs = $count > 0 ? $sumMs / $count : null;

        $p95Ms = $this->p95DurationMs($class);

        $lastRunRaw = $redis->command('get', [KeyPrefix::make("last_run:{$class}")]);
        $lastRunAt = is_string($lastRunRaw) && $lastRunRaw !== ''
            ? Date::parse($lastRunRaw)
            : null;

        return new JobClassMetrics(
            class: $class,
            processed24h: $processed,
            failed24h: $failed,
            avgDurationMs: $avgMs,
            maxDurationMs: $maxMs,
            p95DurationMs: $p95Ms,
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

    public function p95DurationMs(string $class): ?int
    {
        $samples = $this->redis()->command('lrange', [
            KeyPrefix::make("duration:samples:{$class}"),
            0,
            -1,
        ]);

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
     *
     * @return list<array{timestamp: int, processed: int, failed: int}>
     */
    public function hourlyThroughput(int $hours = 24): array
    {
        [$timestamps, $bucketIndex] = $this->buildHourlyTimeline($hours);
        $classes = $this->jobClasses();

        $processedCounts = $this->sumPerBucket($classes, $bucketIndex, 'processed');
        $failedCounts = $this->sumPerBucket($classes, $bucketIndex, 'failed');

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
     * @return array{0: list<int>, 1: array<int|string, int>} — [timestamps, bucketIndex]
     */
    private function buildHourlyTimeline(int $hours): array
    {
        $now = Date::now('UTC');
        $timestamps = [];
        $bucketIndex = [];

        for ($i = $hours - 1; $i >= 0; --$i) {
            $hour = $now->copy()->subHours($i)->startOfHour();
            $bucketStr = $hour->format('YmdH');
            $timestamps[] = $hour->getTimestamp();
            $bucketIndex[$bucketStr] = count($timestamps) - 1;
        }

        return [$timestamps, $bucketIndex];
    }

    /**
     * MGET across {prefix}:{class}:{bucket} for all classes × all buckets, then reduce
     * into one integer per bucket.
     *
     * @param  list<string>  $classes
     * @param  array<int|string, int>  $bucketIndex
     * @return list<int>
     */
    private function sumPerBucket(array $classes, array $bucketIndex, string $prefix): array
    {
        $count = count($bucketIndex);
        $counts = [];
        for ($i = 0; $i < $count; ++$i) {
            $counts[] = 0;
        }

        if ($classes === []) {
            return $counts;
        }

        $keys = [];
        $keyMeta = [];
        foreach ($classes as $class) {
            foreach (array_keys($bucketIndex) as $bucketStr) {
                $keys[] = KeyPrefix::make("{$prefix}:{$class}:{$bucketStr}");
                $keyMeta[] = $bucketStr;
            }
        }

        $values = $this->redis()->command('mget', [$keys]);
        if (! is_array($values)) {
            return $counts;
        }

        foreach ($values as $i => $v) {
            if (is_numeric($v) && isset($keyMeta[$i], $bucketIndex[$keyMeta[$i]])) {
                $counts[$bucketIndex[$keyMeta[$i]]] += (int) $v;
            }
        }

        // `array_values` reasserts the list-shape PHPStan lost when we mutated by numeric key.
        return array_values($counts);
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
            // Anchored substring LIKE against the raw JSON payload. Cross-DB
            // LIKE case rules diverge (SQLite ASCII-insensitive, Postgres
            // sensitive, MySQL collation-dependent) — wrap both sides in
            // LOWER() to produce the same match set everywhere (codex review).
            $needle = '%"displayname":"' . strtolower(addslashes($filters->class)) . '%';
            $query->whereRaw('LOWER(payload) LIKE ?', [$needle]);
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
