<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\DTO\JobClassMetrics;

/**
 * Reads the per-class dashboard metrics (24h processed + failed buckets,
 * duration hash, p95 sample list, last run) for every class in one
 * pipelined round trip.
 *
 * @internal
 */
final class ClassMetricsReader
{
    private const int STRIDE = 5;

    /**
     * @param  list<string>  $classes
     * @return list<JobClassMetrics>
     */
    public static function read(RedisConnection $redis, array $classes, ?string $connection): array
    {
        if ($classes === []) {
            return [];
        }

        $batch = new RedisBatch($redis);
        foreach ($classes as $class) {
            $bucketSuffix = $connection === null ? $class : "{$class}:{$connection}";
            // phpredis mGet() takes one array arg; Predis unwraps a single
            // array arg. `[$keys]` satisfies both.
            $batch->command('mget', [self::hourlyBucketKeys("processed:{$bucketSuffix}")]);
            $batch->command('mget', [self::hourlyBucketKeys("failed:{$bucketSuffix}")]);
            $batch->command('hmget', [KeyPrefix::classKey('duration', $class, $connection), ['count', 'sum_ms', 'max_ms']]);
            $batch->command('lrange', [KeyPrefix::classKey('duration:samples', $class, $connection), 0, -1]);
            $batch->command('get', [KeyPrefix::classKey('last_run', $class, $connection)]);
        }

        $results = $batch->execute();

        $out = [];
        foreach ($classes as $i => $class) {
            $offset = $i * self::STRIDE;
            $out[] = self::decode(
                $class,
                $results[$offset] ?? null,
                $results[$offset + 1] ?? null,
                $results[$offset + 2] ?? null,
                $results[$offset + 3] ?? null,
                $results[$offset + 4] ?? null,
            );
        }

        return $out;
    }

    /**
     * 95th percentile of a duration sample list (nearest rank), or null when
     * it holds no numeric sample.
     */
    public static function p95(mixed $samples): ?int
    {
        if (! is_array($samples)) {
            return null;
        }

        $nums = [];
        foreach ($samples as $sample) {
            if (is_numeric($sample)) {
                $nums[] = (int) $sample;
            }
        }

        if ($nums === []) {
            return null;
        }

        sort($nums);

        $idx = (int) ceil(0.95 * count($nums)) - 1;

        return $nums[max(0, min(count($nums) - 1, $idx))];
    }

    private static function decode(string $class, mixed $processed, mixed $failed, mixed $duration, mixed $samples, mixed $lastRun): JobClassMetrics
    {
        // phpredis returns HMGET keyed by field, Predis as a list.
        $values = is_array($duration) ? array_values($duration) : [];
        $count = is_numeric($values[0] ?? null) ? (int) $values[0] : 0;
        $sumMs = is_numeric($values[1] ?? null) ? (float) $values[1] : 0.0;
        $maxMs = is_numeric($values[2] ?? null) ? (int) $values[2] : null;

        return new JobClassMetrics(
            class: $class,
            processed24h: self::sum($processed),
            failed24h: self::sum($failed),
            avgDurationMs: $count > 0 ? $sumMs / $count : null,
            maxDurationMs: $maxMs,
            p95DurationMs: self::p95($samples),
            lastRunAt: is_string($lastRun) && $lastRun !== '' ? Date::parse($lastRun) : null,
        );
    }

    private static function sum(mixed $values): int
    {
        if (! is_array($values)) {
            return 0;
        }

        $total = 0;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $total += (int) $value;
            }
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private static function hourlyBucketKeys(string $suffix): array
    {
        $now = Date::now('UTC');
        $keys = [];

        for ($i = 0; $i < 24; ++$i) {
            $keys[] = KeyPrefix::make("{$suffix}:" . $now->copy()->subHours($i)->format('YmdH'));
        }

        return $keys;
    }
}
