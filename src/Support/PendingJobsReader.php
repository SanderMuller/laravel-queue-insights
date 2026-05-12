<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

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
     * @return list<array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
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

    /**
     * Cross-queue pending aggregator: gather candidates from every configured
     * queue with one ZRANGEBYSCORE-WITHSCORES round-trip per queue, sort
     * globally by `available_at`, then HGETALL only the top `$limit`. Worst-
     * case HGETALL count stays bounded by `$limit` (capped at 200) regardless
     * of how many queues are backed up.
     *
     * @param  list<array{connection: string, queue: string}>  $configuredQueues
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public static function allPending(array $configuredQueues, int $limit): array
    {
        $now = (string) Date::now()->getTimestamp();

        return self::allAcrossQueues($configuredQueues, $limit, '-inf', $now);
    }

    /**
     * Cross-queue delayed aggregator: same scaffolding as `allPending`, but
     * the bounds select `available_at > now` (delayed jobs that haven't yet
     * become runnable). Soonest-first.
     *
     * @param  list<array{connection: string, queue: string}>  $configuredQueues
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public static function allDelayed(array $configuredQueues, int $limit): array
    {
        // ZRANGEBYSCORE uses `(N` for an exclusive lower bound — jobs whose
        // available_at == now go to `allPending`, not here.
        $now = '(' . Date::now()->getTimestamp();

        return self::allAcrossQueues($configuredQueues, $limit, $now, '+inf');
    }

    /**
     * Cross-queue in-flight aggregator: reads `inflight-zset:{conn}:{queue}`
     * (written by `RecordJobProcessing` when a worker picks up a job) and
     * orders by `started_at` ascending — longest-running first, so stuck
     * jobs surface at the top of the dashboard list.
     *
     * @param  list<array{connection: string, queue: string}>  $configuredQueues
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public static function allInFlight(array $configuredQueues, int $limit): array
    {
        return self::allAcrossQueues($configuredQueues, $limit, '-inf', '+inf', inFlight: true);
    }

    /**
     * Shared cross-queue aggregator. Stage 1: ZRANGEBYSCORE-WITHSCORES per
     * queue (no hash reads). Stage 2: globally sort + slice. Stage 3: HGETALL
     * only the survivors. Worst-case HGETALL count is bounded by `$limit`
     * regardless of how many queues are backed up.
     *
     * @param  list<array{connection: string, queue: string}>  $configuredQueues
     * @return list<array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    private static function allAcrossQueues(array $configuredQueues, int $limit, string $min, string $max, bool $inFlight = false): array
    {
        if ($limit <= 0) {
            return [];
        }

        $effective = min($limit, 200);

        $candidates = [];
        foreach ($configuredQueues as $entry) {
            try {
                $canonical = CanonicalQueueKey::from($entry['queue']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $scores = $inFlight
                ? self::uuidsWithScoresFromKey(KeyPrefix::make("inflight-zset:{$entry['connection']}:{$canonical}"), $min, $max, $effective)
                : self::uuidsWithScores($entry['connection'], $canonical, $min, $max, $effective);
            foreach ($scores as $uuid => $score) {
                $candidates[] = [
                    'uuid' => $uuid,
                    // The zset score is `available_at` for pending/delayed and
                    // `started_at` for in-flight. Stash it under a dedicated
                    // sort field so the post-hydration ordering doesn't fall
                    // back to the hash's `available_at` (which is queue time,
                    // not pickup time, for in-flight rows — would push stuck
                    // jobs down the list behind newer executions).
                    '__sort' => $score,
                    'connection' => $entry['connection'],
                    'queue' => $entry['queue'],
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn (array $a, array $b): int => $a['__sort'] <=> $b['__sort']);
        $candidates = array_slice($candidates, 0, $effective);

        $byUuid = [];
        foreach ($candidates as $candidate) {
            $byUuid[$candidate['uuid']] = $candidate;
        }

        $out = [];
        foreach (self::hydrate(array_keys($byUuid)) as $row) {
            $candidate = $byUuid[$row['uuid']] ?? null;
            if ($candidate === null) {
                continue;
            }

            $out[] = $row + [
                'connection' => $candidate['connection'],
                'queue' => $candidate['queue'],
                '__sort' => $candidate['__sort'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['__sort'] <=> $b['__sort']);

        // Drop the internal sort key so the public row shape stays clean —
        // callers see the documented `{uuid, class, ..., started_at}` keys
        // only.
        return array_map(static function (array $row): array {
            unset($row['__sort']);

            return $row;
        }, $out);
    }

    /**
     * Single-uuid lookup, used by the dashboard's pending modal as a fallback
     * when the requested uuid sits outside the capped 50-row aggregate
     * windows. Reads the `pending:{uuid}` hash directly — same source as the
     * cross-queue aggregator's per-uuid hydration, but skips the zset
     * round-trips. Returns null when the hash has been raced out (worker
     * grabbed it) or never existed (legacy job, pending tracking disabled
     * at queue time).
     *
     * @return array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $hash = $redis->command('hgetall', [KeyPrefix::make("pending:{$uuid}")]);
        if (! is_array($hash) || $hash === []) {
            return null;
        }

        $class = $hash['class'] ?? null;
        $connection = $hash['connection'] ?? null;
        $queue = $hash['queue'] ?? null;
        $queuedAt = $hash['queued_at'] ?? null;
        $availableAt = $hash['available_at'] ?? null;

        if (
            ! is_string($class) || $class === ''
            || ! is_string($connection) || $connection === ''
            || ! is_string($queue) || $queue === ''
            || ! is_numeric($queuedAt)
            || ! is_numeric($availableAt)
        ) {
            return null;
        }

        $batchId = $hash['batch_id'] ?? null;
        $state = $hash['state'] ?? null;
        $startedAt = $hash['started_at'] ?? null;
        $parentUuid = $hash['parent_uuid'] ?? null;
        $attempts = $hash['attempts'] ?? null;

        return [
            'uuid' => $uuid,
            'class' => $class,
            'connection' => $connection,
            'queue' => $queue,
            'queued_at' => (int) $queuedAt,
            'available_at' => (int) $availableAt,
            'batch_id' => is_string($batchId) && $batchId !== '' ? $batchId : null,
            'state' => is_string($state) && $state !== '' ? $state : null,
            'started_at' => is_numeric($startedAt) ? (int) $startedAt : null,
            'parent_uuid' => is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null,
            'attempts' => is_numeric($attempts) ? (int) $attempts : null,
        ];
    }

    public static function trackedCount(string $connection, string $queue): int
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $value = $redis->command('zcard', [self::zsetKey($connection, $queue)]);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Cheap pre-pass for cross-queue aggregation: returns `[uuid => score]`
     * for the zset slice without hydrating per-uuid `pending:{uuid}` hashes.
     * Callers globally sort + slice, then call `hydrate()` on the survivors —
     * that keeps the HGETALL fan-out bounded by the global cap, not by
     * per-queue depth × queue count.
     *
     * @return array<string, int>
     */
    public static function uuidsWithScores(string $connection, string $queue, string $min, string $max, int $limit): array
    {
        return self::uuidsWithScoresFromKey(self::zsetKey($connection, $queue), $min, $max, $limit);
    }

    /**
     * Same as `uuidsWithScores` but takes a fully-qualified zset key. Lets the
     * cross-queue aggregator switch between `pending-zset:` and
     * `inflight-zset:` without duplicating the ZRANGEBYSCORE plumbing.
     *
     * @return array<string, int>
     */
    private static function uuidsWithScoresFromKey(string $key, string $min, string $max, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $effectiveLimit = min($limit, 1000);

        $result = $redis->command('zrangebyscore', [
            $key,
            $min,
            $max,
            ['LIMIT' => [0, $effectiveLimit], 'WITHSCORES' => true],
        ]);

        if (! is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $uuid => $score) {
            if (! is_string($uuid)) {
                continue;
            }

            if ($uuid === '') {
                continue;
            }

            if (! is_numeric($score)) {
                continue;
            }

            $out[$uuid] = (int) $score;
        }

        return $out;
    }

    /**
     * Hydrate a list of pending uuids into row arrays, dropping any whose
     * `pending:{uuid}` hash has been raced out from under us by a worker.
     *
     * @param  list<string>  $uuids
     * @return list<array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int}>
     */
    public static function hydrate(array $uuids): array
    {
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
        }

        return $out;
    }

    /**
     * @return array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int, attempts: ?int}|null
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

        $batchId = $hash['batch_id'] ?? null;
        $state = $hash['state'] ?? null;
        $startedAt = $hash['started_at'] ?? null;
        $parentUuid = $hash['parent_uuid'] ?? null;
        $attempts = $hash['attempts'] ?? null;

        return [
            'uuid' => $uuid,
            'class' => $class,
            'queued_at' => (int) $queuedAt,
            'available_at' => (int) $availableAt,
            'batch_id' => is_string($batchId) && $batchId !== '' ? $batchId : null,
            'state' => is_string($state) && $state !== '' ? $state : null,
            'started_at' => is_numeric($startedAt) ? (int) $startedAt : null,
            'parent_uuid' => is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null,
            'attempts' => is_numeric($attempts) ? (int) $attempts : null,
        ];
    }

    private static function zsetKey(string $connection, string $queue): string
    {
        $canonical = CanonicalQueueKey::fromOrDefault($queue, $connection);

        return KeyPrefix::make("pending-zset:{$connection}:{$canonical}");
    }
}
