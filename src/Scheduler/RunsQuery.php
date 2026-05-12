<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisPipeline;

/**
 * Recent-runs index reader, extracted from `ScheduleReader` to keep
 * the main facade under PHPStan's cognitive-complexity ceiling.
 *
 * Walks `qi:sched:runs:all` (newest first), pulls the per-run hash for
 * each candidate, projects to a stable display shape, and applies
 * filters in PHP.
 *
 * @phpstan-type RunFilters array{task?: ?string, status?: ?string, host?: ?string, from_ms?: ?int, to_ms?: ?int}
 * @phpstan-type RunRow array{
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
 * }
 */
final class RunsQuery
{
    /**
     * Hard cap on candidates pulled from `qi:sched:runs:all` for any
     * single read. The dashboard pages through this window — deeper
     * history is not surfaced and `countRuns` saturates at this number.
     * Bounded so a runaway zset can't fan out into a megabyte-scale
     * `hgetall` pipeline.
     */
    private const int MAX_CANDIDATES = 2000;

    /**
     * @param  RunFilters  $filters
     * @return list<RunRow>
     */
    public function recentRuns(array $filters, int $perPage, int $page): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $rows = $this->collectMatchingRows($filters, min(self::MAX_CANDIDATES, $perPage * $page * 5));
        $offset = ($page - 1) * $perPage;

        return array_slice($rows, $offset, $perPage);
    }

    /**
     * Empty-filter path returns `ZCARD` (clamped to {@see MAX_CANDIDATES})
     * — one round-trip instead of the 2k-candidate `collectMatchingRows`
     * walk. NOTE: ZCARD counts every zset member regardless of whether
     * the per-run hash still exists. If the run-hash TTL expires before
     * the zset member is swept, this returns a slightly inflated total
     * vs. the row-walking path (operator may see "1-10 of 250" while the
     * page renders fewer rows). Bounded by sweep cadence + hash TTL
     * matching; acceptable trade-off for the round-trip saved.
     *
     * @param  RunFilters  $filters
     */
    public function countRuns(array $filters): int
    {
        if ($this->filtersAreEmpty($filters)) {
            $card = Redis::connection(Config::string('redis_connection', 'default'))
                ->command('zcard', [KeyPrefix::make('sched:runs:all')]);

            return is_numeric($card) ? min(self::MAX_CANDIDATES, (int) $card) : 0;
        }

        return count($this->collectMatchingRows($filters, self::MAX_CANDIDATES));
    }

    /**
     * @param  RunFilters  $filters
     */
    private function filtersAreEmpty(array $filters): bool
    {
        foreach (['task', 'status', 'host'] as $name) {
            $value = $filters[$name] ?? null;
            if (is_string($value) && $value !== '') {
                return false;
            }
        }

        foreach (['from_ms', 'to_ms'] as $name) {
            if (is_int($filters[$name] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  RunFilters  $filters
     * @return list<RunRow>
     */
    private function collectMatchingRows(array $filters, int $maxCandidates): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $allKey = KeyPrefix::make('sched:runs:all');
        $members = $redis->command('zrevrange', [$allKey, 0, $maxCandidates - 1]);
        if (! is_array($members) || $members === []) {
            return [];
        }

        // Pre-split member ids so the pipeline closure stays a plain list of
        // HGETALL calls — keeps result-index alignment trivial.
        $pairs = [];
        foreach ($members as $member) {
            if (! is_string($member)) {
                continue;
            }

            if ($member === '') {
                continue;
            }

            $parts = explode(':', $member, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $pairs[] = $parts;
        }

        if ($pairs === []) {
            return [];
        }

        // Single pipeline for the N per-run HGETALL fan-out. ZREVRANGE
        // result order is preserved in the pipeline response.
        $hashes = RedisPipeline::run($redis, static function (mixed $pipe) use ($pairs): void {
            foreach ($pairs as [$taskKey, $runId]) {
                $pipe->hgetall(KeyPrefix::make("sched:run:{$taskKey}:{$runId}"));
            }
        });

        $rows = [];
        foreach ($pairs as $idx => [$taskKey, $runId]) {
            $hash = $hashes[$idx] ?? null;
            if (! is_array($hash)) {
                continue;
            }

            if ($hash === []) {
                continue;
            }

            $row = $this->projectRunRow($taskKey, $runId, $hash);
            if (! $this->matchesFilters($row, $filters)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $hash
     * @return RunRow
     */
    private function projectRunRow(string $taskKey, string $runId, array $hash): array
    {
        return [
            'task_key' => $taskKey,
            'run_id' => $runId,
            'started_at_ms' => HashFields::int($hash, 'started_at'),
            'finished_at_ms' => HashFields::nullableInt($hash, 'finished_at'),
            'runtime_ms' => HashFields::nullableInt($hash, 'runtime_ms'),
            'exit_code' => HashFields::nullableInt($hash, 'exit_code'),
            'status' => HashFields::string($hash, 'status', 'starting'),
            'skip_reason' => HashFields::nullableString($hash['skip_reason'] ?? null),
            'host_id' => HashFields::string($hash, 'host_id', 'unknown'),
            'is_background' => HashFields::bool01($hash, 'is_background'),
            'exception' => HashFields::decodeJson($hash['exception'] ?? null),
            'output' => HashFields::nullableString($hash['output'] ?? null),
        ];
    }

    /**
     * @param  RunRow  $row
     * @param  RunFilters  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        $task = $filters['task'] ?? null;
        if (is_string($task) && $task !== '' && $row['task_key'] !== $task) {
            return false;
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && $row['status'] !== $status) {
            return false;
        }

        $host = $filters['host'] ?? null;
        if (is_string($host) && $host !== '' && $row['host_id'] !== $host) {
            return false;
        }

        $fromMs = $filters['from_ms'] ?? null;
        if (is_int($fromMs) && $row['started_at_ms'] < $fromMs) {
            return false;
        }

        $toMs = $filters['to_ms'] ?? null;

        return ! (is_int($toMs) && $row['started_at_ms'] > $toMs);
    }
}
