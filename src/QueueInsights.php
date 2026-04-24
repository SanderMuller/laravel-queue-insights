<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\DTO\JobClassMetrics;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
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

    public function lastSnapshotAt(string $connection, string $queue): ?Carbon
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
     * @return list<array<array-key, mixed>>
     */
    public function recentFailed(int $limit = 100): array
    {
        try {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->toArray();
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
