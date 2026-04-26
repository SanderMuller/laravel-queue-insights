<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Reads from the pending-tracking Redis storage written by `RecordJobQueued`.
 *
 * Lives in `Support/` (not on `QueueInsights`) so the per-queue zset / hash
 * reads can grow more sophisticated (pipelining, race-condition signals,
 * tracking-gap reconciliation) without inflating the service-layer cognitive
 * complexity budget.
 */
final class PendingJobsReader
{
    /**
     * Range over the pending-tracking zset and hydrate each uuid's hash.
     * Min/max use Redis ZRANGEBYSCORE syntax — `-inf`, `+inf`, or `(N` for
     * exclusive bounds.
     *
     * @return list<array{uuid: string, class: string, queued_at: int, available_at: int}>
     */
    public static function readZset(string $connection, string $queue, string $min, string $max, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $key = self::zsetKey($connection, $queue);
        $effectiveLimit = min($limit, 1000);

        $uuids = $redis->command('zrangebyscore', [$key, $min, $max, ['LIMIT' => [0, $effectiveLimit]]]);
        if (! is_array($uuids) || $uuids === []) {
            return [];
        }

        $out = [];
        foreach ($uuids as $uuid) {
            if (! is_string($uuid)) {
                continue;
            }

            if ($uuid === '') {
                continue;
            }

            $row = self::readHash($uuid);
            if ($row !== null) {
                $out[] = $row;
            }

            // Missing hash for a uuid in the zset = race condition (worker
            // grabbed the job between our ZRANGEBYSCORE and HGETALL). Skip
            // rather than render a blank row.
        }

        return $out;
    }

    public static function trackedCount(string $connection, string $queue): int
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $value = $redis->command('zcard', [self::zsetKey($connection, $queue)]);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{uuid: string, class: string, queued_at: int, available_at: int}|null
     */
    private static function readHash(string $uuid): ?array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $hash = $redis->command('hgetall', [KeyPrefix::make("pending:{$uuid}")]);
        if (! is_array($hash) || $hash === []) {
            return null;
        }

        $class = $hash['class'] ?? null;
        $queuedAt = $hash['queued_at'] ?? null;
        $availableAt = $hash['available_at'] ?? null;

        if (! is_string($class) || $class === '' || ! is_numeric($queuedAt) || ! is_numeric($availableAt)) {
            return null;
        }

        return [
            'uuid' => $uuid,
            'class' => $class,
            'queued_at' => (int) $queuedAt,
            'available_at' => (int) $availableAt,
        ];
    }

    private static function zsetKey(string $connection, string $queue): string
    {
        $canonical = $queue === '' ? 'default' : CanonicalQueueKey::from($queue);

        return KeyPrefix::make("pending-zset:{$connection}:{$canonical}");
    }
}
