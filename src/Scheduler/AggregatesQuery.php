<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Hourly-aggregate / sparkline / headline-stats reader, extracted from
 * `ScheduleReader` to keep the main facade under PHPStan's
 * cognitive-complexity ceiling. Reads only — never writes Redis.
 */
final class AggregatesQuery
{
    private ?Connection $redis = null;

    private function redis(): Connection
    {
        return $this->redis ??= Redis::connection(Config::string('redis_connection', 'default'));
    }

    /**
     * @param  list<array{task_key: string}>|list<array<string, mixed>>  $tasks
     * @return array{
     *   runs_24h: int,
     *   failed_24h: int,
     *   skipped_24h: int,
     *   hung_24h: int,
     *   missed_24h: int,
     *   p95_runtime_ms: ?int,
     *   slowest_task_key: ?string,
     *   slowest_task_p95_ms: ?int,
     * }
     */
    public function headlineStats(array $tasks): array
    {
        return $this->headlineStatsFromComputed($this->computeStatsForTasks($tasks));
    }

    /**
     * Variant that takes already-computed per-task stats so the dashboard
     * panel doesn't re-fetch them after walking the same task list for
     * its needs-attention/healthy split.
     *
     * @param  list<array{task_key: string, stats: array{runs: int, failed: int, skipped: int, hung: int, missed: int, last_run_at_ms: ?int, p95_ms: ?int}}>  $tasksWithStats
     * @return array{
     *   runs_24h: int,
     *   failed_24h: int,
     *   skipped_24h: int,
     *   hung_24h: int,
     *   missed_24h: int,
     *   p95_runtime_ms: ?int,
     *   slowest_task_key: ?string,
     *   slowest_task_p95_ms: ?int,
     * }
     */
    public function headlineStatsFromComputed(array $tasksWithStats): array
    {
        $runs = 0;
        $failed = 0;
        $skipped = 0;
        $hung = 0;
        $missed = 0;
        $p95Samples = [];
        $slowestTaskKey = null;
        $slowestTaskP95 = null;

        foreach ($tasksWithStats as $row) {
            $stats = $row['stats'];
            $runs += $stats['runs'];
            $failed += $stats['failed'];
            $skipped += $stats['skipped'];
            $hung += $stats['hung'];
            $missed += $stats['missed'];

            if ($stats['p95_ms'] === null) {
                continue;
            }

            $p95Samples[] = $stats['p95_ms'];
            if ($slowestTaskP95 === null || $stats['p95_ms'] > $slowestTaskP95) {
                $slowestTaskP95 = $stats['p95_ms'];
                $slowestTaskKey = $row['task_key'];
            }
        }

        $avgP95 = $p95Samples === []
            ? null
            : (int) round(array_sum($p95Samples) / count($p95Samples));

        return [
            'runs_24h' => $runs,
            'failed_24h' => $failed,
            'skipped_24h' => $skipped,
            'hung_24h' => $hung,
            'missed_24h' => $missed,
            'p95_runtime_ms' => $avgP95,
            'slowest_task_key' => $slowestTaskKey,
            'slowest_task_p95_ms' => $slowestTaskP95,
        ];
    }

    /**
     * @param  list<array{task_key: string}>|list<array<string, mixed>>  $tasks
     * @return list<array{task_key: string, stats: array{runs: int, failed: int, skipped: int, hung: int, missed: int, last_run_at_ms: ?int, p95_ms: ?int}}>
     */
    public function computeStatsForTasks(array $tasks): array
    {
        $keys = [];
        foreach ($tasks as $task) {
            $key = is_string($task['task_key'] ?? null) ? $task['task_key'] : null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            return [];
        }

        $now = Date::now()->getTimestamp();
        $buckets = [];
        for ($i = 0; $i < 24; ++$i) {
            $buckets[] = Date::createFromTimestamp($now - ($i * 3600))->format('YmdH');
        }

        // Single pipeline for the entire per-task fan-out: 49 commands per
        // task collapse into one round-trip across all tasks.
        $results = $this->redis()->pipeline(static function ($pipe) use ($keys, $buckets): void {
            foreach ($keys as $taskKey) {
                foreach ($buckets as $bucket) {
                    $pipe->hgetall(KeyPrefix::make("sched:agg:{$taskKey}:{$bucket}"));
                    $pipe->lrange(KeyPrefix::make("sched:samples:{$taskKey}:{$bucket}"), 0, -1);
                }
                $pipe->hgetall(KeyPrefix::make("sched:counters:{$taskKey}"));
            }
        });

        $bucketsPerTask = count($buckets);
        $stride = ($bucketsPerTask * 2) + 1;
        $out = [];
        foreach ($keys as $idx => $taskKey) {
            $base = $idx * $stride;
            $runs = 0;
            $failed = 0;
            $samples = [];

            for ($b = 0; $b < $bucketsPerTask; ++$b) {
                $aggHash = $results[$base + ($b * 2)] ?? null;
                if (is_array($aggHash) && $aggHash !== []) {
                    $s = HashFields::int($aggHash, 'success_count');
                    $f = HashFields::int($aggHash, 'failed_count');
                    $runs += $s + $f;
                    $failed += $f;
                }

                $list = $results[$base + ($b * 2) + 1] ?? null;
                if (is_array($list)) {
                    foreach ($list as $entry) {
                        if (is_numeric($entry)) {
                            $samples[] = (int) $entry;
                        }
                    }
                }
            }

            $countersHash = $results[$base + ($bucketsPerTask * 2)] ?? null;
            $countersHash = is_array($countersHash) ? $countersHash : [];

            $out[] = [
                'task_key' => $taskKey,
                'stats' => [
                    'runs' => $runs,
                    'failed' => $failed,
                    'skipped' => HashFields::int($countersHash, 'total_skipped'),
                    'hung' => HashFields::int($countersHash, 'total_hung'),
                    'missed' => HashFields::int($countersHash, 'total_missed'),
                    'last_run_at_ms' => HashFields::nullableInt($countersHash, 'last_run_at'),
                    'p95_ms' => $this->p95FromSamples($samples),
                ],
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   runs: int,
     *   failed: int,
     *   skipped: int,
     *   hung: int,
     *   missed: int,
     *   last_run_at_ms: ?int,
     *   p95_ms: ?int,
     * }
     */
    public function taskWindowStats(string $taskKey): array
    {
        [$runs, $failed, $samples] = $this->aggregateBucketSums($taskKey);
        [$skipped, $hung, $missed, $lastRun] = $this->lifetimeCountersSlice($taskKey);

        return [
            'runs' => $runs,
            'failed' => $failed,
            'skipped' => $skipped,
            // `total_hung`/`total_missed` are lifetime counters; the
            // 24h aggregate buckets don't track them. Sweeper writes
            // are bounded by `runs_index_max`; in steady-state these
            // reflect "outstanding hung/missed runs visible in the
            // recent window" closely enough for the headline tile.
            'hung' => $hung,
            'missed' => $missed,
            'last_run_at_ms' => $lastRun,
            'p95_ms' => $this->p95FromSamples($samples),
        ];
    }

    /**
     * @param  list<array{task_key: string}>|list<array<string, mixed>>  $tasks
     * @return list<array{hour: string, success: int, failed: int}>
     */
    public function throughputSparkline(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $keys = [];
        foreach ($tasks as $task) {
            $key = is_string($task['task_key'] ?? null) ? $task['task_key'] : null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            return [];
        }

        $now = Date::now()->getTimestamp();
        // hour-ordered oldest→newest so output stays in the same shape
        // the view expects (left-to-right bars are 23h-ago → now).
        $buckets = [];
        $hourLabels = [];
        for ($i = 23; $i >= 0; --$i) {
            $ts = $now - ($i * 3600);
            $buckets[] = Date::createFromTimestamp($ts)->format('YmdH');
            $hourLabels[] = Date::createFromTimestamp($ts)->format('H:00');
        }

        // Single pipeline for the entire (hours × tasks) fan-out.
        $results = $this->redis()->pipeline(static function ($pipe) use ($buckets, $keys): void {
            foreach ($buckets as $bucket) {
                foreach ($keys as $taskKey) {
                    $pipe->hgetall(KeyPrefix::make("sched:agg:{$taskKey}:{$bucket}"));
                }
            }
        });

        $bars = [];
        $tasksPerHour = count($keys);
        foreach ($buckets as $hourIdx => $bucket) {
            $success = 0;
            $failed = 0;
            $base = $hourIdx * $tasksPerHour;
            for ($t = 0; $t < $tasksPerHour; ++$t) {
                $hash = $results[$base + $t] ?? null;
                if (is_array($hash) && $hash !== []) {
                    $success += HashFields::int($hash, 'success_count');
                    $failed += HashFields::int($hash, 'failed_count');
                }
            }
            $bars[] = [
                'hour' => $hourLabels[$hourIdx],
                'success' => $success,
                'failed' => $failed,
            ];
        }

        return $bars;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}  [runs, failed, samples]
     */
    private function aggregateBucketSums(string $taskKey): array
    {
        $redis = $this->redis();
        $now = Date::now()->getTimestamp();

        $runs = 0;
        $failed = 0;
        $samples = [];

        for ($i = 0; $i < 24; ++$i) {
            $bucket = Date::createFromTimestamp($now - ($i * 3600))->format('YmdH');
            $hash = $redis->command('hgetall', [KeyPrefix::make("sched:agg:{$taskKey}:{$bucket}")]);
            if (is_array($hash) && $hash !== []) {
                $success = HashFields::int($hash, 'success_count');
                $f = HashFields::int($hash, 'failed_count');
                $runs += $success + $f;
                $failed += $f;
            }

            $list = $redis->command('lrange', [KeyPrefix::make("sched:samples:{$taskKey}:{$bucket}"), 0, -1]);
            if (is_array($list)) {
                foreach ($list as $entry) {
                    if (is_numeric($entry)) {
                        $samples[] = (int) $entry;
                    }
                }
            }
        }

        return [$runs, $failed, $samples];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: ?int}  [skipped, hung, missed, last_run_at]
     */
    private function lifetimeCountersSlice(string $taskKey): array
    {
        $redis = $this->redis();
        $hash = $redis->command('hgetall', [KeyPrefix::make("sched:counters:{$taskKey}")]);
        if (! is_array($hash)) {
            return [0, 0, 0, null];
        }

        return [
            HashFields::int($hash, 'total_skipped'),
            HashFields::int($hash, 'total_hung'),
            HashFields::int($hash, 'total_missed'),
            HashFields::nullableInt($hash, 'last_run_at'),
        ];
    }

    /**
     * @param  list<int>  $samples
     */
    private function p95FromSamples(array $samples): ?int
    {
        if (count($samples) < 5) {
            return null;
        }

        sort($samples);

        return $samples[(int) floor(0.95 * (count($samples) - 1))];
    }
}
