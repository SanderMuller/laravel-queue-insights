<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Carbon\CarbonInterface;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;

/**
 * Pipelined batch reader for `QueueRowsBuilder`. Collapses the per-queue
 * snapshot fan-out (live:depth + live:delayed + live:inflight +
 * snapshot:error + lastSnapshotAt + pendingTrackedCount) into one Redis
 * round-trip. Extracted from `QueueInsights` so the service class stays
 * under PHPStan's cognitive-complexity budget.
 *
 * @internal
 */
final class QueueRowSnapshotReader
{
    /**
     * @param  list<array{connection: string, queue: string}>  $pairs  canonicalised (connection, queue) tuples
     * @return list<array{depth: int, delayed: ?int, inflight: ?int, error: ?string, last_at: ?CarbonInterface, pending_tracked_count: ?int}>
     */
    public static function read(RedisConnection $redis, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $pendingEnabled = Config::bool('pending.enabled', true);

        $results = RedisPipeline::run($redis, static function (mixed $client) use ($pairs, $pendingEnabled): void {
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
            $out[] = self::decodeRow(
                $results[$offset] ?? null,
                $results[$offset + 1] ?? null,
                $results[$offset + 2] ?? null,
                $results[$offset + 3] ?? null,
                $results[$offset + 4] ?? null,
                $pendingEnabled ? ($results[$offset + 5] ?? null) : null,
                $pendingEnabled,
            );
        }

        return $out;
    }

    /**
     * @return array{depth: int, delayed: ?int, inflight: ?int, error: ?string, last_at: ?CarbonInterface, pending_tracked_count: ?int}
     */
    private static function decodeRow(
        mixed $depth,
        mixed $delayed,
        mixed $inflight,
        mixed $error,
        mixed $tail,
        mixed $tracked,
        bool $pendingEnabled,
    ): array {
        return [
            'depth' => self::asInt($depth, 0),
            'delayed' => self::asNullableInt($delayed),
            'inflight' => self::asNullableInt($inflight),
            'error' => is_string($error) && $error !== '' ? $error : null,
            'last_at' => self::decodeLastAt($tail),
            'pending_tracked_count' => $pendingEnabled ? self::asInt($tracked, 0) : null,
        ];
    }

    private static function asInt(mixed $value, int $default): int
    {
        return is_string($value) || is_int($value) ? (int) $value : $default;
    }

    private static function asNullableInt(mixed $value): ?int
    {
        return is_string($value) || is_int($value) ? (int) $value : null;
    }

    private static function decodeLastAt(mixed $tail): ?CarbonInterface
    {
        if (! is_array($tail) || $tail === []) {
            return null;
        }

        $score = array_values($tail)[0];

        return is_numeric($score) ? Date::createFromTimestamp((int) $score) : null;
    }
}
