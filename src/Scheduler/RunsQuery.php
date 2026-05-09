<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

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
     * @param  RunFilters  $filters
     * @return list<RunRow>
     */
    public function recentRuns(array $filters, int $perPage, int $page): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $rows = $this->collectMatchingRows($filters, min(2000, $perPage * $page * 5));
        $offset = ($page - 1) * $perPage;

        return array_slice($rows, $offset, $perPage);
    }

    /**
     * @param  RunFilters  $filters
     */
    public function countRuns(array $filters): int
    {
        return count($this->collectMatchingRows($filters, 2000));
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

        $rows = [];
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

            [$taskKey, $runId] = $parts;
            $hash = $redis->command('hgetall', [KeyPrefix::make("sched:run:{$taskKey}:{$runId}")]);
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
