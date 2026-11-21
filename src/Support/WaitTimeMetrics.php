<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Per-queue wait-time percentile reader. Data layout (written by
 * `RecordJobProcessing`):
 *   - `wait:{connection}:{queue}` ZSET — member = uuid, score = insertion ts.
 *     Rank-trim to 1000 keeps the most recent (codex review).
 *   - `wait:{uuid}` string — ms wait, 7d TTL. MGET joins the ZSET back
 *     to its samples for percentile compute.
 */
final class WaitTimeMetrics
{
    /**
     * Format a wait/runtime millisecond reading as a compact human-readable
     * string: under 1s → `142ms`, under 1m → `1.4s`, otherwise `2.3m`.
     * Null reads as the em-dash placeholder so view sites can blindly call
     * the formatter without per-site null handling.
     */
    public static function format(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        if ($ms < 1000) {
            return number_format($ms) . 'ms';
        }

        if ($ms < 60_000) {
            return number_format($ms / 1000, 1) . 's';
        }

        return number_format($ms / 60_000, 1) . 'm';
    }

    /**
     * @return array{p50: ?int, p95: ?int}
     */
    public static function percentiles(string $connection, string $queue): array
    {
        return self::percentilesMany([['connection' => $connection, 'queue' => $queue]])[0];
    }

    /**
     * Two pipelined round trips for any number of queues: every ZSET, then
     * every MGET of the sample keys.
     *
     * @param  list<array{connection: string, queue: string}>  $pairs
     * @return list<array{p50: ?int, p95: ?int}>
     */
    public static function percentilesMany(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $batch = new RedisBatch(self::redis());
        foreach ($pairs as $pair) {
            $batch->command('zrange', [KeyPrefix::make("wait:{$pair['connection']}:{$pair['queue']}"), 0, -1]);
        }

        $sampleKeys = array_map(self::sampleKeys(...), $batch->execute());

        $slots = [];
        foreach ($sampleKeys as $i => $keys) {
            if ($keys !== []) {
                $slots[$i] = $batch->command('mget', [$keys]);
            }
        }

        $values = $batch->execute();

        $out = [];
        foreach (array_keys($pairs) as $i) {
            $out[] = self::fromSamples(isset($slots[$i]) ? $values[$slots[$i]] ?? null : null);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function sampleKeys(mixed $uuids): array
    {
        if (! is_array($uuids) || count($uuids) < 10) {
            return [];
        }

        $keys = [];
        foreach ($uuids as $uuid) {
            if (is_string($uuid) && $uuid !== '') {
                $keys[] = KeyPrefix::make("wait:{$uuid}");
            }
        }

        return $keys;
    }

    /**
     * @return array{p50: ?int, p95: ?int}
     */
    private static function fromSamples(mixed $values): array
    {
        $samples = [];
        if (is_array($values)) {
            foreach ($values as $value) {
                // A `wait:{uuid}` key can expire between the ZSET read and
                // the MGET; only live samples count.
                if (is_numeric($value)) {
                    $samples[] = (int) $value;
                }
            }
        }

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
