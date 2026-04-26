<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Per-queue wait-time percentile reader. Lifted out of `QueueInsights`
 * to keep that class under PHPStan's cognitive-complexity ceiling — the
 * percentile + sample-collection path on its own added ~5-6 points of
 * cyclomatic weight.
 *
 * The data layout (written by `RecordJobProcessing`) is:
 *   - `wait:{connection}:{queue}` ZSET: member = uuid, score = insertion ts.
 *     Rank-trim to 1000 keeps the most recent (codex review).
 *   - `wait:{uuid}` string: ms wait, 7d TTL. MGET joins the ZSET back
 *     to its samples for percentile compute.
 */
final class WaitTimeMetrics
{
    /**
     * @return array{p50: ?int, p95: ?int}
     */
    public static function percentiles(string $connection, string $queue): array
    {
        $samples = self::samples($connection, $queue);

        if (count($samples) < 10) {
            return ['p50' => null, 'p95' => null];
        }

        sort($samples);

        return [
            'p50' => self::pick($samples, 0.50),
            'p95' => self::pick($samples, 0.95),
        ];
    }

    /**
     * @return list<int>
     */
    private static function samples(string $connection, string $queue): array
    {
        $key = KeyPrefix::make("wait:{$connection}:{$queue}");

        $uuids = self::redis()->command('zrange', [$key, 0, -1]);

        if (! is_array($uuids) || count($uuids) < 10) {
            return [];
        }

        $sampleKeys = [];
        foreach ($uuids as $uuid) {
            if (is_string($uuid) && $uuid !== '') {
                $sampleKeys[] = KeyPrefix::make("wait:{$uuid}");
            }
        }

        if ($sampleKeys === []) {
            return [];
        }

        $values = self::redis()->command('mget', [$sampleKeys]);

        if (! is_array($values)) {
            return [];
        }

        $samples = [];
        foreach ($values as $value) {
            // `wait:{uuid}` keys can have expired between the ZSET read
            // and this MGET — skip nulls so the percentile reflects only
            // live samples.
            if (is_numeric($value)) {
                $samples[] = (int) $value;
            }
        }

        return $samples;
    }

    /**
     * @param  list<int>  $sortedSamples
     */
    private static function pick(array $sortedSamples, float $percentile): int
    {
        $count = count($sortedSamples);
        $idx = (int) ceil($percentile * $count) - 1;

        return $sortedSamples[max(0, min($count - 1, $idx))];
    }

    private static function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
