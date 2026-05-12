<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Read-side queries for the scheduler subsystem. Mirrors `BatchReader`
 * — kept out of the listener path so the dashboard / CLI / future
 * Livewire surfaces share one shape definition.
 *
 * Phase 1 ships the minimal set the `schedule:list` command needs.
 * Dashboard-specific reads (per-task drilldown, recent runs join,
 * sparkline data) land in Phase 2 alongside the Livewire surfaces.
 */
final class ScheduleReader
{
    private ?Connection $redis = null;

    private function redis(): Connection
    {
        return $this->redis ??= Redis::connection(Config::string('redis_connection', 'default'));
    }

    /**
     * All snapshotted tasks in registration order. Each row carries
     * the JSON summary written by `ScheduleSnapshotter` plus a
     * derived `task_key` field.
     *
     * @return list<array{
     *   task_key: string,
     *   description: ?string,
     *   command: string,
     *   expression: string,
     *   timezone: ?string,
     *   runInBackground: bool,
     *   onOneServer: bool,
     *   evenInMaintenanceMode: bool,
     *   withoutOverlapping: bool,
     *   mutexName: string,
     *   type: string,
     * }>
     */
    public function tasks(): array
    {
        $redis = $this->redis();
        $orderRaw = $redis->command('lrange', [KeyPrefix::make('sched:tasks:order'), 0, -1]);
        if (! is_array($orderRaw) || $orderRaw === []) {
            return [];
        }

        // Defense in depth against legacy polluted order lists. Pre-fix
        // installs may carry duplicate entries in `sched:tasks:order`
        // from a prior non-atomic rebuild race; dedup at read time so
        // the dashboard recovers without waiting for the next rebuild
        // to roll the snapshot over. New writes go through the atomic
        // Lua rewrite and won't introduce duplicates.
        $keys = [];
        $seen = [];
        foreach ($orderRaw as $key) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $keys[] = $key;
        }

        if ($keys === []) {
            return [];
        }

        // Single HMGET pulls every task summary from the `sched:tasks` hash
        // in one round-trip, replacing N serial HGETs.
        // Pass fields as a single array — both predis (variadic-normalised)
        // and phpredis (strict `hMget(key, array)`) accept that shape; the
        // variadic spread form blows up on phpredis ('expects exactly 2').
        // phpredis returns an associative array keyed by field; Predis
        // returns a positional list. `array_values` normalises both into
        // the input-field order so the positional access below works for
        // both client drivers — same pattern as `QueueInsights::classMetrics`.
        $raw = $redis->command('hmget', [KeyPrefix::make('sched:tasks'), $keys]);
        if (! is_array($raw)) {
            return [];
        }
        $values = array_values($raw);

        $rows = [];
        foreach ($keys as $idx => $key) {
            $json = $values[$idx] ?? null;
            if (! is_string($json)) {
                continue;
            }

            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }

            $rows[] = [
                'task_key' => $key,
                'description' => HashFields::nullableString($decoded['description'] ?? null),
                'command' => HashFields::string($decoded, 'command', ''),
                'expression' => HashFields::string($decoded, 'expression', '* * * * *'),
                'timezone' => HashFields::nullableString($decoded['timezone'] ?? null),
                'runInBackground' => (bool) ($decoded['runInBackground'] ?? false),
                'onOneServer' => (bool) ($decoded['onOneServer'] ?? false),
                'evenInMaintenanceMode' => (bool) ($decoded['evenInMaintenanceMode'] ?? false),
                'withoutOverlapping' => (bool) ($decoded['withoutOverlapping'] ?? false),
                'mutexName' => HashFields::string($decoded, 'mutexName', ''),
                'type' => HashFields::string($decoded, 'type', 'command'),
            ];
        }

        return $rows;
    }

    /**
     * Lifetime counters for one task. Returns zeros when the task has
     * never run (counter hash absent).
     *
     * @return array{
     *   total_runs: int,
     *   total_failed: int,
     *   total_skipped: int,
     *   last_run_at: ?int,
     *   last_failed_at: ?int,
     *   last_success_at: ?int,
     * }
     */
    public function counters(string $taskKey): array
    {
        $redis = $this->redis();
        $value = $redis->command('hgetall', [KeyPrefix::make("sched:counters:{$taskKey}")]);
        $hash = is_array($value) ? $value : [];

        return [
            'total_runs' => HashFields::int($hash, 'total_runs'),
            'total_failed' => HashFields::int($hash, 'total_failed'),
            'total_skipped' => HashFields::int($hash, 'total_skipped'),
            'last_run_at' => HashFields::nullableInt($hash, 'last_run_at'),
            'last_failed_at' => HashFields::nullableInt($hash, 'last_failed_at'),
            'last_success_at' => HashFields::nullableInt($hash, 'last_success_at'),
        ];
    }

    /**
     * Walk every `qi:sched:running:*` pointer hash and yield a
     * (taskKey → running-state) map. Drives the hung-task sweeper.
     *
     * Uses `SCAN` rather than `KEYS` so a host with hundreds of
     * tasks doesn't block Redis on a single cursor.
     *
     * @return array<string, array{run_id: string, started_at_ms: int, expected_finish_at_ms: int}>
     */
    public function runningTasks(): array
    {
        $redis = $this->redis();
        $indexKey = KeyPrefix::make('sched:running-index');

        $taskKeys = $redis->command('zrange', [$indexKey, 0, -1]);
        if (! is_array($taskKeys) || $taskKeys === []) {
            return [];
        }

        $found = [];
        foreach ($taskKeys as $taskKey) {
            if (! is_string($taskKey)) {
                continue;
            }

            if ($taskKey === '') {
                continue;
            }

            $hash = $redis->command('hgetall', [KeyPrefix::make("sched:running:{$taskKey}")]);
            if (! is_array($hash) || $hash === []) {
                // Index drift — stale member with no backing pointer.
                // Self-heal so the sweeper doesn't churn on it again.
                $redis->command('zrem', [$indexKey, $taskKey]);

                continue;
            }

            $runId = HashFields::nullableString($hash['run_id'] ?? null);
            $startedAt = HashFields::nullableInt($hash, 'started_at_ms');
            $expected = HashFields::nullableInt($hash, 'expected_finish_at_ms');
            if ($runId === null) {
                continue;
            }

            if ($startedAt === null) {
                continue;
            }

            if ($expected === null) {
                continue;
            }

            $found[$taskKey] = [
                'run_id' => $runId,
                'started_at_ms' => $startedAt,
                'expected_finish_at_ms' => $expected,
            ];
        }

        return $found;
    }

    /**
     * Member ids (start-time scores) for a given task's run zset within
     * a time window — drives the missed-run reconciler's "did we see
     * this expected fire?" lookup.
     *
     * @return list<int> sorted ascending
     */
    public function startingTimestampsBetween(string $taskKey, int $fromMs, int $toMs): array
    {
        if ($fromMs > $toMs) {
            return [];
        }

        $redis = $this->redis();
        $key = KeyPrefix::make("sched:runs:{$taskKey}");
        $members = $redis->command('zrangebyscore', [$key, $fromMs, $toMs]);
        if (! is_array($members)) {
            return [];
        }

        $out = [];
        foreach ($members as $member) {
            if (! is_string($member)) {
                continue;
            }

            if ($member === '') {
                continue;
            }

            $score = $redis->command('zscore', [$key, $member]);
            if (is_numeric($score)) {
                $out[] = (int) $score;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Job uuids dispatched during the given scheduled run. Walks the
     * `qi:sched:run-jobs:{runId}` zset written by `RecordJobQueued`
     * when `ScheduleContext` was active.
     *
     * @return list<string>
     */
    public function jobsDispatchedDuring(string $runId): array
    {
        if ($runId === '') {
            return [];
        }

        $redis = $this->redis();
        $members = $redis->command('zrange', [KeyPrefix::make("sched:run-jobs:{$runId}"), 0, -1]);
        if (! is_array($members)) {
            return [];
        }

        $out = [];
        foreach ($members as $member) {
            if (is_string($member) && $member !== '') {
                $out[] = $member;
            }
        }

        return $out;
    }

    /**
     * Distribution of `host_id` across the recent runs of one task —
     * answers "is `onOneServer` distributing fairly across hosts?".
     *
     * @return array<string, int>
     */
    public function hostDistribution(string $taskKey, int $sampleLimit = 200): array
    {
        if ($taskKey === '' || $sampleLimit < 1) {
            return [];
        }

        $redis = $this->redis();
        $runIds = $redis->command('zrevrange', [
            KeyPrefix::make("sched:runs:{$taskKey}"),
            0,
            $sampleLimit - 1,
        ]);
        if (! is_array($runIds)) {
            return [];
        }

        $counts = [];
        foreach ($runIds as $runId) {
            if (! is_string($runId)) {
                continue;
            }

            if ($runId === '') {
                continue;
            }

            $host = $redis->command('hget', [KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'host_id']);
            if (! is_string($host)) {
                continue;
            }

            if ($host === '') {
                continue;
            }

            $counts[$host] = ($counts[$host] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Hydrated detail for a single scheduled run. `output` is intentionally
     * omitted — it can grow to `max_output_bytes` and the dashboard fetches
     * it via the separate `runOutput()` accessor only when the modal is
     * actually rendering it.
     *
     * Returns `null` when the per-run hash is absent (post-7d TTL or
     * never-existed deep-link). Callers MUST treat null as the "Expired"
     * state — never 500.
     *
     * @return ?array{
     *   task_key: string,
     *   run_id: string,
     *   started_at_ms: ?int,
     *   finished_at_ms: ?int,
     *   runtime_ms: ?int,
     *   exit_code: ?int,
     *   status: string,
     *   skip_reason: ?string,
     *   host_id: string,
     *   is_background: bool,
     *   recovered_from_hung: bool,
     *   exception: ?array<array-key, mixed>,
     *   has_output: bool,
     *   correlated_jobs: list<string>,
     * }
     */
    public function runDetail(string $taskKey, string $runId): ?array
    {
        if ($taskKey === '' || $runId === '') {
            return null;
        }

        $hash = $this->redis()
            ->command('hgetall', [KeyPrefix::make("sched:run:{$taskKey}:{$runId}")]);
        if (! is_array($hash) || $hash === []) {
            return null;
        }

        return [
            'task_key' => $taskKey,
            'run_id' => $runId,
            'started_at_ms' => HashFields::nullableInt($hash, 'started_at'),
            'finished_at_ms' => HashFields::nullableInt($hash, 'finished_at'),
            'runtime_ms' => HashFields::nullableInt($hash, 'runtime_ms'),
            'exit_code' => HashFields::nullableInt($hash, 'exit_code'),
            'status' => HashFields::string($hash, 'status', 'starting'),
            'skip_reason' => HashFields::nullableString($hash['skip_reason'] ?? null),
            'host_id' => HashFields::string($hash, 'host_id', 'unknown'),
            'is_background' => HashFields::bool01($hash, 'is_background'),
            'recovered_from_hung' => HashFields::bool01($hash, 'recovered_from_hung'),
            'exception' => HashFields::decodeJson($hash['exception'] ?? null),
            'has_output' => is_string($hash['output'] ?? null) && $hash['output'] !== '',
            'correlated_jobs' => $this->jobsDispatchedDuring($runId),
        ];
    }

    /**
     * Per-run captured stdout/stderr blob. Separated from `runDetail` so the
     * panel-level recent-runs page never accidentally pulls multi-KB output
     * payloads while paging.
     */
    public function runOutput(string $taskKey, string $runId): ?string
    {
        if ($taskKey === '' || $runId === '') {
            return null;
        }

        $value = $this->redis()
            ->command('hget', [KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'output']);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return ?int unix milliseconds, or null when no snapshot has been written
     */
    public function snapshotAtMs(): ?int
    {
        $value = $this->redis()
            ->command('get', [KeyPrefix::make('sched:snapshot:at')]);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Page through the global recent-runs index, optionally narrowed by
     * task / status / host / time-window. Filtering happens after the
     * ZRANGE because the index member shape is `{taskKey}:{runId}` —
     * the per-run hash is the only place state lives.
     *
     * @param  array{task?: ?string, status?: ?string, host?: ?string, from_ms?: ?int, to_ms?: ?int}  $filters
     * @return list<array{
     *   task_key: string,
     *   run_id: string,
     *   started_at_ms: int,
     *   finished_at_ms: ?int,
     *   runtime_ms: ?int,
     *   exit_code: ?int,
     *   status: string,
     *   skip_reason: ?string,
     *   host_id: string,
     *   is_background: bool,
     *   exception: ?array<array-key, mixed>,
     *   output: ?string,
     * }>
     */
    public function recentRuns(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        return (new RunsQuery())->recentRuns($filters, $perPage, $page);
    }

    /**
     * Total count of runs matching the same filter shape `recentRuns()`
     * accepts. Walks the same candidate window so the count stays
     * consistent with the displayed slice.
     *
     * @param  array{task?: ?string, status?: ?string, host?: ?string, from_ms?: ?int, to_ms?: ?int}  $filters
     */
    public function countRuns(array $filters = []): int
    {
        return (new RunsQuery())->countRuns($filters);
    }

    /**
     * Six-tile rollup over the last 24h of runs. `runs_24h` /
     * `failed_24h` / `skipped_24h` count from per-bucket aggregates;
     * `hung_24h` / `missed_24h` are derived from the recent-runs index
     * (no dedicated counter — those statuses are bounded by sweeper
     * frequency in Phase 3).
     *
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
    public function headlineStats(): array
    {
        return (new AggregatesQuery())->headlineStats($this->tasks());
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
        return (new AggregatesQuery())->taskWindowStats($taskKey);
    }

    /**
     * Distinct host ids seen across the last 24h of recent runs. Drives
     * the dashboard host-filter dropdown.
     *
     * @return list<string>
     */
    public function distinctHosts(): array
    {
        $hosts = [];
        foreach ($this->recentRuns(filters: [], perPage: 200, page: 1) as $row) {
            $host = $row['host_id'];
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        sort($hosts);

        return $hosts;
    }

    /**
     * 24h hourly throughput for the schedule sparkline. Each entry is one
     * hour; ordered oldest → newest.
     *
     * @return list<array{hour: string, success: int, failed: int}>
     */
    public function throughputSparkline(): array
    {
        return (new AggregatesQuery())->throughputSparkline($this->tasks());
    }
}
