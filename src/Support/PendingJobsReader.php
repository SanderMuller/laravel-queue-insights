<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/** Reads pending-tracking Redis storage written by `RecordJobQueued`. */
final class PendingJobsReader
{
    private static function connection(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }

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

        $redis = self::connection();
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

        // Canonicalise the (connection, queue) tuples up front so the
        // pipelined Redis fan-out only sees valid pairs. Invalid entries are
        // skipped silently — boot-time ConfigValidator catches them earlier.
        $resolved = [];
        foreach ($configuredQueues as $entry) {
            try {
                $canonical = CanonicalQueueKey::from($entry['queue']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $resolved[] = [
                'connection' => $entry['connection'],
                'queue' => $entry['queue'],
                'canonical' => $canonical,
            ];
        }

        if ($resolved === []) {
            return [];
        }

        // Pipeline the per-queue ZRANGEBYSCORE-WITHSCORES fan-out into one
        // Redis round-trip — previously this loop fired N sequential
        // ZRANGEBYSCOREs (one per configured queue) per category, and the
        // dashboard renders all three categories (pending / delayed /
        // in-flight) per poll. On non-loopback Redis each RTT is the
        // dominant cost.
        $redis = self::connection();
        $keyPrefix = $inFlight ? 'inflight-zset' : 'pending-zset';

        $results = RedisPipeline::run($redis, static function (mixed $client) use ($resolved, $keyPrefix, $min, $max, $effective): void {
            foreach ($resolved as $pair) {
                $client->zrangebyscore(
                    KeyPrefix::make("{$keyPrefix}:{$pair['connection']}:{$pair['canonical']}"),
                    $min,
                    $max,
                    ['LIMIT' => [0, $effective], 'WITHSCORES' => true],
                );
            }
        });

        $candidates = [];
        foreach ($resolved as $i => $pair) {
            $raw = $results[$i] ?? [];
            foreach (self::normaliseScores($raw) as $uuid => $score) {
                $candidates[] = [
                    'uuid' => $uuid,
                    // The zset score is `available_at` for pending/delayed and
                    // `started_at` for in-flight. Stash it under a dedicated
                    // sort field so the post-hydration ordering doesn't fall
                    // back to the hash's `available_at` (which is queue time,
                    // not pickup time, for in-flight rows — would push stuck
                    // jobs down the list behind newer executions).
                    '__sort' => $score,
                    'connection' => $pair['connection'],
                    'queue' => $pair['queue'],
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
     * @return array{uuid: string, class: string, connection: string, queue: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int, parent_uuid: ?string, attempts: ?int, payload_body: ?string, payload_displayName: ?string, payload_maxTries: ?string, payload_timeout: ?string, payload_backoff: ?string, payload_note: ?string, payload_reason: ?string, payload_error: ?string, payload_size: ?string}|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        $redis = self::connection();
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

        // Pull optional `payload_*` fields written by RecordJobQueued when
        // `pending.capture.payloads` is on. Same set + same naming as the
        // `parseHash` path so the pending-modal sees identical row shape
        // whether the row came from the by-uuid lookup or the per-queue
        // hydrate fan-out.
        $payloadFields = self::extractPayloadFields($hash);

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
            'payload_body' => $payloadFields['payload_body'],
            'payload_displayName' => $payloadFields['payload_displayName'],
            'payload_maxTries' => $payloadFields['payload_maxTries'],
            'payload_timeout' => $payloadFields['payload_timeout'],
            'payload_backoff' => $payloadFields['payload_backoff'],
            'payload_note' => $payloadFields['payload_note'],
            'payload_reason' => $payloadFields['payload_reason'],
            'payload_error' => $payloadFields['payload_error'],
            'payload_size' => $payloadFields['payload_size'],
        ];
    }

    /**
     * Pull any `payload_*` fields from a hash result into a flat assoc
     * array of `payload_body|displayName|maxTries|...` keys with string-or-
     * null values. Centralised so `findByUuid` and `parseHash` stay in
     * lock-step on the field surface they expose.
     *
     * @param  array<array-key, mixed>  $hash
     * @return array<string, ?string>
     */
    private static function extractPayloadFields(array $hash): array
    {
        $out = [];
        foreach (['body', 'displayName', 'maxTries', 'timeout', 'backoff', 'note', 'reason', 'error', 'size'] as $key) {
            $value = $hash['payload_' . $key] ?? null;
            $out['payload_' . $key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $out;
    }

    public static function trackedCount(string $connection, string $queue): int
    {
        $redis = self::connection();
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

        $redis = self::connection();
        $effectiveLimit = min($limit, 1000);

        $result = $redis->command('zrangebyscore', [
            $key,
            $min,
            $max,
            ['LIMIT' => [0, $effectiveLimit], 'WITHSCORES' => true],
        ]);

        return self::normaliseScores($result);
    }

    /**
     * Decode the `member => score` shape produced by a WITHSCORES-flagged
     * ZRANGEBYSCORE. Shared between the single-key reader and the pipelined
     * cross-queue aggregator so both branches stay consistent if one driver
     * tweaks the return shape.
     *
     * @return array<string, int>
     */
    private static function normaliseScores(mixed $result): array
    {
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
        $valid = [];
        foreach ($uuids as $uuid) {
            if (! is_string($uuid)) {
                continue;
            }

            if ($uuid === '') {
                continue;
            }

            $valid[] = $uuid;
        }

        if ($valid === []) {
            return [];
        }

        $redis = self::connection();

        // Pipeline the per-uuid HGETALL fan-out. Previously this method
        // issued one HGETALL per uuid sequentially (50 + 50 + 50 = 150 RTT
        // per warm dashboard render with seeded pending/delayed/inflight
        // tables). On non-loopback Redis each RTT is the dominant cost.
        $results = RedisPipeline::run($redis, static function (mixed $client) use ($valid): void {
            foreach ($valid as $uuid) {
                $client->hgetall(KeyPrefix::make("pending:{$uuid}"));
            }
        });

        $out = [];
        foreach ($valid as $i => $uuid) {
            $row = self::parseHash($uuid, $results[$i] ?? null);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int, attempts: ?int, payload_body: ?string, payload_displayName: ?string, payload_maxTries: ?string, payload_timeout: ?string, payload_backoff: ?string, payload_note: ?string, payload_reason: ?string, payload_error: ?string, payload_size: ?string}|null
     */
    private static function readHash(string $uuid): ?array
    {
        $redis = self::connection();
        $hash = $redis->command('hgetall', [KeyPrefix::make("pending:{$uuid}")]);

        return self::parseHash($uuid, $hash);
    }

    /**
     * @return array{uuid: string, class: string, queued_at: int, available_at: int, batch_id: ?string, state: ?string, started_at: ?int, parent_uuid: ?string, attempts: ?int, payload_body: ?string, payload_displayName: ?string, payload_maxTries: ?string, payload_timeout: ?string, payload_backoff: ?string, payload_note: ?string, payload_reason: ?string, payload_error: ?string, payload_size: ?string}|null
     */
    private static function parseHash(string $uuid, mixed $hash): ?array
    {
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

        // Pull any `payload_*` fields RecordJobQueued wrote when
        // `pending.capture.payloads` is on. Shared with findByUuid via
        // `extractPayloadFields` so the row shape is identical regardless
        // of read path.
        $payloadFields = self::extractPayloadFields($hash);

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
            'payload_body' => $payloadFields['payload_body'],
            'payload_displayName' => $payloadFields['payload_displayName'],
            'payload_maxTries' => $payloadFields['payload_maxTries'],
            'payload_timeout' => $payloadFields['payload_timeout'],
            'payload_backoff' => $payloadFields['payload_backoff'],
            'payload_note' => $payloadFields['payload_note'],
            'payload_reason' => $payloadFields['payload_reason'],
            'payload_error' => $payloadFields['payload_error'],
            'payload_size' => $payloadFields['payload_size'],
        ];
    }

    private static function zsetKey(string $connection, string $queue): string
    {
        $canonical = CanonicalQueueKey::fromOrDefault($queue, $connection);

        return KeyPrefix::make("pending-zset:{$connection}:{$canonical}");
    }
}
