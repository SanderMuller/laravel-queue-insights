<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;

/**
 * Cross-class / cross-bucket counter aggregator. Reads
 * `{prefix}:{class}[:{connection}]:{YmdH}` keys with one MGET round-trip
 * and reduces into a per-bucket integer series. Extracted from
 * `QueueInsights` to keep that class's cognitive complexity bounded.
 *
 * @internal
 */
final class HourlyBucketReader
{
    /**
     * @return array{0: list<int>, 1: array<int|string, int>} — [timestamps, bucketIndex]
     */
    public static function buildTimeline(int $hours): array
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
     * into one integer per bucket. When `$connection` is non-null, the keys
     * are `{prefix}:{class}:{connection}:{bucket}` (the dual-write variant).
     *
     * @param  list<string>  $classes
     * @param  array<int|string, int>  $bucketIndex
     * @return list<int>
     */
    public static function sumPerBucket(RedisConnection $redis, array $classes, array $bucketIndex, string $prefix, ?string $connection = null): array
    {
        $count = count($bucketIndex);
        $counts = array_fill(0, $count, 0);

        if ($classes === []) {
            return $counts;
        }

        $keys = [];
        $keyMeta = [];
        foreach ($classes as $class) {
            foreach (array_keys($bucketIndex) as $bucketStr) {
                $keys[] = $connection === null
                    ? KeyPrefix::make("{$prefix}:{$class}:{$bucketStr}")
                    : KeyPrefix::make("{$prefix}:{$class}:{$connection}:{$bucketStr}");
                $keyMeta[] = $bucketStr;
            }
        }

        $values = $redis->command('mget', [$keys]);
        if (! is_array($values)) {
            return $counts;
        }

        foreach ($values as $i => $v) {
            if (is_numeric($v) && isset($keyMeta[$i], $bucketIndex[$keyMeta[$i]])) {
                $counts[$bucketIndex[$keyMeta[$i]]] += (int) $v;
            }
        }

        return array_values($counts);
    }
}
