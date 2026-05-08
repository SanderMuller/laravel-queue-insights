<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Single source of truth for the `qi:sched:*` write paths. Listeners
 * call into this helper so the per-run hash, per-task indexes,
 * aggregates, counters and `running` pointer stay shape-stable.
 *
 * @phpstan-type RunStartingArgs array{
 *   task_key: string,
 *   run_id: string,
 *   started_at_ms: int,
 *   host_id: string,
 *   is_background: bool,
 *   expected_finish_at_ms: int,
 * }
 * @phpstan-type RunFinishedArgs array{
 *   task_key: string,
 *   run_id: string,
 *   finished_at_ms: int,
 *   runtime_ms: int,
 *   exit_code: int,
 *   status: 'success'|'failed'|'hung'|'skipped'|'missed',
 *   output: ?string,
 *   exception: ?array<string, mixed>,
 * }
 */
final class RunStore
{
    public function connection(): Connection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }

    /**
     * @param  RunStartingArgs  $args
     */
    public function recordStarting(array $args): void
    {
        $redis = $this->connection();
        $runKey = KeyPrefix::make("sched:run:{$args['task_key']}:{$args['run_id']}");
        $runsKey = KeyPrefix::make("sched:runs:{$args['task_key']}");
        $allKey = KeyPrefix::make('sched:runs:all');
        $runningKey = KeyPrefix::make("sched:running:{$args['task_key']}");

        $ttl = Config::int('scheduler.retention.run_ttl_seconds', 604800);
        $cap = Config::int('scheduler.retention.runs_index_max', 10000);

        foreach ([
            'started_at' => (string) $args['started_at_ms'],
            'status' => 'starting',
            'host_id' => $args['host_id'],
            'is_background' => $args['is_background'] ? '1' : '0',
        ] as $field => $value) {
            $redis->command('hset', [$runKey, $field, $value]);
        }

        $redis->command('expire', [$runKey, $ttl]);

        $redis->command('zadd', [$runsKey, $args['started_at_ms'], $args['run_id']]);
        $redis->command('expire', [$runsKey, $ttl]);

        $redis->command('zadd', [$allKey, $args['started_at_ms'], $args['task_key'] . ':' . $args['run_id']]);
        // Cap the global recent-runs index. -($cap + 1) keeps the most
        // recent $cap entries; older drop on every Starting tick.
        $redis->command('zremrangebyrank', [$allKey, 0, -($cap + 1)]);

        foreach ([
            'run_id' => $args['run_id'],
            'started_at_ms' => (string) $args['started_at_ms'],
            'expected_finish_at_ms' => (string) $args['expected_finish_at_ms'],
        ] as $field => $value) {
            $redis->command('hset', [$runningKey, $field, $value]);
        }

        // Maintain an explicit index zset (member = taskKey, score =
        // expected_finish_at_ms) so the sweeper can find hung runs
        // without falling back to KEYS — KEYS responses include the
        // framework-side Redis prefix which the per-key calls strip
        // automatically. The zset's score is the only signal the
        // sweeper needs (`< now → hung`).
        $redis->command('zadd', [
            KeyPrefix::make('sched:running-index'),
            $args['expected_finish_at_ms'],
            $args['task_key'],
        ]);
        // No EXPIRE on `running` — sweeper relies on the key surviving
        // past expected_finish_at_ms to flag hung tasks (Phase 3).
    }

    /**
     * @param  RunFinishedArgs  $args
     */
    public function recordFinish(array $args): void
    {
        $redis = $this->connection();
        $runKey = KeyPrefix::make("sched:run:{$args['task_key']}:{$args['run_id']}");
        $runningKey = KeyPrefix::make("sched:running:{$args['task_key']}");
        $countersKey = KeyPrefix::make("sched:counters:{$args['task_key']}");

        $bucket = Date::createFromTimestamp((int) ($args['finished_at_ms'] / 1000))->format('YmdH');
        $aggKey = KeyPrefix::make("sched:agg:{$args['task_key']}:{$bucket}");
        $samplesKey = KeyPrefix::make("sched:samples:{$args['task_key']}:{$bucket}");

        $aggTtl = Config::int('scheduler.retention.aggregate_ttl_hours', 192) * 3600;
        $runTtl = Config::int('scheduler.retention.run_ttl_seconds', 604800);

        // Late-arriving Finished/Failed for a run the sweeper already
        // marked hung: keep the original status flip but record a
        // `recovered_from_hung=1` flag so the dashboard can label the
        // recovered row distinctly. Closes spatie #94 / #110 false-positive
        // hung reports that never resolve.
        $priorStatus = $redis->command('hget', [$runKey, 'status']);
        $recoveredFromHung = is_string($priorStatus) && $priorStatus === 'hung';

        $fields = [
            'finished_at' => (string) $args['finished_at_ms'],
            'runtime_ms' => (string) $args['runtime_ms'],
            'exit_code' => (string) $args['exit_code'],
            'status' => $args['status'],
        ];
        if ($recoveredFromHung) {
            $fields['recovered_from_hung'] = '1';
        }

        if ($args['output'] !== null) {
            $fields['output'] = $args['output'];
        }

        if ($args['exception'] !== null) {
            $encoded = json_encode($args['exception']);
            if (is_string($encoded)) {
                $fields['exception'] = $encoded;
            }
        }

        foreach ($fields as $field => $value) {
            $redis->command('hset', [$runKey, $field, $value]);
        }

        $redis->command('expire', [$runKey, $runTtl]);
        $redis->command('del', [$runningKey]);
        $redis->command('zrem', [KeyPrefix::make('sched:running-index'), $args['task_key']]);

        $counterField = match ($args['status']) {
            'failed' => 'failed_count',
            'success' => 'success_count',
            default => null,
        };
        if ($counterField !== null) {
            $redis->command('hincrby', [$aggKey, $counterField, 1]);
        }

        $redis->command('hincrby', [$aggKey, 'runtime_sum_ms', $args['runtime_ms']]);
        $redis->command('expire', [$aggKey, $aggTtl]);

        // Per-bucket runtime samples — bounded list, percentile computed
        // at read time (mirrors WaitTimeMetrics for queue jobs).
        $redis->command('rpush', [$samplesKey, (string) $args['runtime_ms']]);
        $redis->command('ltrim', [$samplesKey, -500, -1]);
        $redis->command('expire', [$samplesKey, $aggTtl]);

        // Lifetime counters — no TTL.
        $redis->command('hincrby', [$countersKey, 'total_runs', 1]);
        $redis->command('hset', [$countersKey, 'last_run_at', (string) $args['finished_at_ms']]);
        if ($args['status'] === 'failed') {
            $redis->command('hincrby', [$countersKey, 'total_failed', 1]);
            $redis->command('hset', [$countersKey, 'last_failed_at', (string) $args['finished_at_ms']]);
        } elseif ($args['status'] === 'success') {
            $redis->command('hset', [$countersKey, 'last_success_at', (string) $args['finished_at_ms']]);
        }
    }

    /**
     * Synthesize a missed-run record from the sweeper. No `started_at` was
     * ever observed on a host — the row exists only to make the missed
     * fire visible in the dashboard + drive the alert event.
     */
    public function recordMissed(string $taskKey, string $runId, int $expectedAtMs): void
    {
        $redis = $this->connection();
        $runKey = KeyPrefix::make("sched:run:{$taskKey}:{$runId}");
        $runsKey = KeyPrefix::make("sched:runs:{$taskKey}");
        $allKey = KeyPrefix::make('sched:runs:all');
        $countersKey = KeyPrefix::make("sched:counters:{$taskKey}");

        $ttl = Config::int('scheduler.retention.run_ttl_seconds', 604800);
        $cap = Config::int('scheduler.retention.runs_index_max', 10000);

        foreach ([
            'started_at' => (string) $expectedAtMs,
            'status' => 'missed',
            'host_id' => 'sweeper',
        ] as $field => $value) {
            $redis->command('hset', [$runKey, $field, $value]);
        }

        $redis->command('expire', [$runKey, $ttl]);

        $redis->command('zadd', [$runsKey, $expectedAtMs, $runId]);
        $redis->command('expire', [$runsKey, $ttl]);
        $redis->command('zadd', [$allKey, $expectedAtMs, $taskKey . ':' . $runId]);
        $redis->command('zremrangebyrank', [$allKey, 0, -($cap + 1)]);

        $redis->command('hincrby', [$countersKey, 'total_missed', 1]);
    }

    /**
     * Mark a started-but-not-finished run as hung. Removes the
     * `running` pointer so the same run isn't re-detected on every
     * sweep tick. Counter increments lifetime `total_hung`.
     */
    public function recordHung(string $taskKey, string $runId): void
    {
        $redis = $this->connection();
        $runKey = KeyPrefix::make("sched:run:{$taskKey}:{$runId}");
        $runningKey = KeyPrefix::make("sched:running:{$taskKey}");
        $countersKey = KeyPrefix::make("sched:counters:{$taskKey}");

        $redis->command('hset', [$runKey, 'status', 'hung']);
        $redis->command('del', [$runningKey]);
        $redis->command('zrem', [KeyPrefix::make('sched:running-index'), $taskKey]);
        $redis->command('hincrby', [$countersKey, 'total_hung', 1]);
    }

    public function recordSkipped(string $taskKey, string $runId, int $atMs, string $reason, string $hostId): void
    {
        $redis = $this->connection();
        $runKey = KeyPrefix::make("sched:run:{$taskKey}:{$runId}");
        $runsKey = KeyPrefix::make("sched:runs:{$taskKey}");
        $allKey = KeyPrefix::make('sched:runs:all');
        $countersKey = KeyPrefix::make("sched:counters:{$taskKey}");

        $ttl = Config::int('scheduler.retention.run_ttl_seconds', 604800);
        $cap = Config::int('scheduler.retention.runs_index_max', 10000);

        foreach ([
            'started_at' => (string) $atMs,
            'finished_at' => (string) $atMs,
            'status' => 'skipped',
            'skip_reason' => $reason,
            'host_id' => $hostId,
        ] as $field => $value) {
            $redis->command('hset', [$runKey, $field, $value]);
        }

        $redis->command('expire', [$runKey, $ttl]);

        $redis->command('zadd', [$runsKey, $atMs, $runId]);
        $redis->command('expire', [$runsKey, $ttl]);
        $redis->command('zadd', [$allKey, $atMs, $taskKey . ':' . $runId]);
        $redis->command('zremrangebyrank', [$allKey, 0, -($cap + 1)]);

        $redis->command('hincrby', [$countersKey, 'total_skipped', 1]);
    }

    /**
     * Stamp exception JSON on an existing run hash. Used by the Failed
     * listener's enrich path when `Finished` already wrote the run for
     * the same fire (foreground command/closure failure dual-fires both
     * events — see Laravel's `ScheduleRunCommand::handle`).
     *
     * @param  array<string, mixed>  $exception
     */
    public function stampException(string $taskKey, string $runId, array $exception): void
    {
        $encoded = json_encode($exception);
        if (! is_string($encoded)) {
            return;
        }

        $this->connection()->command('hset', [
            KeyPrefix::make("sched:run:{$taskKey}:{$runId}"),
            'exception',
            $encoded,
        ]);
    }

    /**
     * Most-recently-started run id for a task whose finish_at is within
     * `$withinSeconds`. Returns null when nothing matches — caller
     * synthesizes. Drives the Failed listener's enrich path.
     */
    public function recentlyFinishedRunId(string $taskKey, int $withinSeconds = 60): ?string
    {
        $redis = $this->connection();
        $latest = $redis->command('zrevrange', [KeyPrefix::make("sched:runs:{$taskKey}"), 0, 0]);
        if (! is_array($latest) || ! isset($latest[0]) || ! is_string($latest[0]) || $latest[0] === '') {
            return null;
        }

        $runId = $latest[0];

        $finishedAt = $redis->command('hget', [KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'finished_at']);
        if (! is_numeric($finishedAt)) {
            return null;
        }

        $ageMs = Date::now()->getTimestampMs() - (int) $finishedAt;

        return $ageMs <= $withinSeconds * 1000 ? $runId : null;
    }

    /**
     * @return ?array{run_id: string, started_at_ms: int, expected_finish_at_ms: int}
     */
    public function readRunning(string $taskKey): ?array
    {
        $redis = $this->connection();
        $value = $redis->command('hgetall', [KeyPrefix::make("sched:running:{$taskKey}")]);
        if (! is_array($value) || $value === []) {
            return null;
        }

        $runId = HashFields::nullableString($value['run_id'] ?? null);
        $startedAt = HashFields::nullableInt($value, 'started_at_ms');
        $expected = HashFields::nullableInt($value, 'expected_finish_at_ms');
        if ($runId === null || $startedAt === null || $expected === null) {
            return null;
        }

        return [
            'run_id' => $runId,
            'started_at_ms' => $startedAt,
            'expected_finish_at_ms' => $expected,
        ];
    }

    /**
     * Rolling p95 of the last `min_runs_for_p95` runtimes across the
     * recent aggregate buckets. Returns null when fewer than the
     * configured floor of samples are available; callers fall back to
     * `grace_seconds * 1000`.
     */
    public function recentP95RuntimeMs(string $taskKey): ?int
    {
        $redis = $this->connection();
        $min = Config::int('scheduler.hung.min_runs_for_p95', 10);

        // Walk the last 24 hourly buckets — bounded, predictable.
        $now = Date::now()->getTimestamp();
        $samples = [];
        for ($i = 0; $i < 24; ++$i) {
            $bucket = Date::createFromTimestamp($now - ($i * 3600))->format('YmdH');
            $list = $redis->command('lrange', [
                KeyPrefix::make("sched:samples:{$taskKey}:{$bucket}"),
                0,
                -1,
            ]);
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $entry) {
                if (is_numeric($entry)) {
                    $samples[] = (int) $entry;
                }
            }

            if (count($samples) >= 500) {
                break;
            }
        }

        if (count($samples) < $min) {
            return null;
        }

        sort($samples);
        $idx = (int) floor(0.95 * (count($samples) - 1));

        return $samples[$idx];
    }
}
