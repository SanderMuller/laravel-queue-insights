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
     * Fields the list-path HMGET pulls per run. Restricted to what the
     * run-row blade + filter pass actually consume — `output` /
     * `exception` (variable-size blobs) and `skip_reason` /
     * `is_background` / `recovered_from_hung` (not rendered on the list)
     * are omitted so the pipelined response stays small.
     *
     * `started_at` is positional element 0 — `mapHmgetReply` uses it as
     * the orphan-detection sentinel because `RunStore::start` always
     * writes it on a real run.
     */
    private const array LIST_FIELDS = [
        'started_at',
        'finished_at',
        'runtime_ms',
        'exit_code',
        'status',
        'host_id',
    ];

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

        // HMGET the metadata fields only. `output` is excluded — it can grow
        // to `scheduler.capture.max_output_bytes` per run, so pipelining 2k
        // candidates' worth would buffer multi-MB into a single Redis reply.
        // The modal fetches it on demand via `ScheduleReader::runOutput()`.
        $hashes = RedisPipeline::run($redis, static function (mixed $pipe) use ($pairs): void {
            foreach ($pairs as [$taskKey, $runId]) {
                $pipe->hmget(
                    KeyPrefix::make("sched:run:{$taskKey}:{$runId}"),
                    self::LIST_FIELDS,
                );
            }
        });

        $rows = [];
        foreach ($pairs as $idx => [$taskKey, $runId]) {
            $hashArray = $this->mapHmgetReply($hashes[$idx] ?? null);
            if ($hashArray === null) {
                continue;
            }

            $row = $this->projectRunRow($taskKey, $runId, $hashArray);
            if (! $this->matchesFilters($row, $filters)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Normalise the HMGET reply to a `field => value` assoc array, or
     * null when the per-run hash is missing/orphan. `started_at` is the
     * orphan sentinel: `RunStore::start` always writes it on a real run,
     * so its absence means the zset member outlived its backing hash
     * (worker grabbed and finished the job between our ZRANGE and HMGET,
     * or the per-run hash TTL'd out).
     *
     * @return array<string, mixed>|null
     */
    private function mapHmgetReply(mixed $reply): ?array
    {
        if (! is_array($reply) || $reply === []) {
            return null;
        }

        $values = array_values($reply);
        if ($values[0] === null || $values[0] === false) {
            return null;
        }

        $out = [];
        foreach (self::LIST_FIELDS as $idx => $field) {
            $out[$field] = $values[$idx] ?? null;
        }

        return $out;
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
            'host_id' => HashFields::string($hash, 'host_id', 'unknown'),
            // List-path-omitted fields: `skip_reason`, `is_background`,
            // `exception`, `output`. The blade doesn't render any of them
            // and `exception` / `output` can each grow to several KiB.
            // Modal hydrates via `ScheduleReader::runDetail` / `runOutput`.
            'skip_reason' => null,
            'is_background' => false,
            'exception' => null,
            'output' => null,
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

        return ! is_int($toMs) || $row['started_at_ms'] <= $toMs;
    }
}
