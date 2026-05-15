<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Carbon\CarbonInterface;
use Illuminate\Bus\Batch;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use Throwable;

/**
 * Reads batch-tracking Redis storage written by `RecordJobQueued`, joined
 * to Laravel's authoritative `Bus::findBatch()` for live counts.
 */
final class BatchReader
{
    /**
     * Recent batches in created-at order (newest first). When `$connection`
     * is non-null, reads from the per-connection index `qi:batches:index:{c}`
     * (first-write-wins under §1 of the v2-gaps spec); otherwise reads the
     * aggregate index `qi:batches:index`.
     *
     * @return list<array{
     *   id: string,
     *   name: ?string,
     *   total_jobs: int,
     *   pending_jobs: int,
     *   processed_jobs: int,
     *   failed_jobs: int,
     *   progress: int,
     *   created_at: ?CarbonInterface,
     *   finished_at: ?CarbonInterface,
     *   cancelled_at: ?CarbonInterface,
     * }>
     */
    public static function recentBatches(int $limit = 50, ?string $connection = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $effectiveLimit = min($limit, Config::int('batches.max_per_query', 100));

        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $indexKey = $connection === null || $connection === ''
            ? KeyPrefix::make('batches:index')
            : KeyPrefix::make("batches:index:{$connection}");
        $ids = $redis->command('zrevrange', [
            $indexKey,
            0,
            $effectiveLimit - 1,
        ]);

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }

            if ($id === '') {
                continue;
            }

            $batch = self::findBatch($id);
            if (! $batch instanceof Batch) {
                // BatchRepository TTL aged the row out of Laravel's storage
                // even though our index still has it. Skip silently — pruning
                // by score on the next JobQueued event will catch up.
                continue;
            }

            $out[] = self::projectBatch($batch);
        }

        return $out;
    }

    /**
     * Single batch view — same shape as `recentBatches()` rows + a uuid list.
     *
     * @return array{
     *   id: string,
     *   name: ?string,
     *   total_jobs: int,
     *   pending_jobs: int,
     *   processed_jobs: int,
     *   failed_jobs: int,
     *   progress: int,
     *   created_at: ?CarbonInterface,
     *   finished_at: ?CarbonInterface,
     *   cancelled_at: ?CarbonInterface,
     *   uuids: list<string>,
     * }|null
     */
    public static function batchDetail(string $batchId, ?string $connection = null): ?array
    {
        if ($batchId === '') {
            return null;
        }

        $batch = self::findBatch($batchId);
        if (! $batch instanceof Batch) {
            return null;
        }

        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        // Scope gate at the batch level — when a connection scope is set,
        // the batch's :connection pointer must match. Mismatch returns null
        // so the modal lands on the empty state instead of leaking another
        // connection's batch through the detailRow() fallback path. Helper
        // lives in BatchScopeFilter to keep this class under PHPStan's
        // cognitive-complexity ceiling.
        if ($connection !== null && $connection !== '' && ! BatchScopeFilter::batchOwnedByConnection($redis, $batchId, $connection)) {
            return null;
        }

        $raw = $redis->command('lrange', [KeyPrefix::make("batch:{$batchId}:uuids"), 0, -1]);

        $uuids = [];
        if (is_array($raw)) {
            foreach ($raw as $u) {
                if (is_string($u) && $u !== '') {
                    $uuids[] = $u;
                }
            }
        }

        if ($connection !== null && $connection !== '' && $uuids !== []) {
            $uuids = BatchScopeFilter::filterUuidsByConnection($redis, $uuids, $connection);
        }

        return self::projectBatch($batch) + ['uuids' => $uuids];
    }

    /**
     * Hydrate a uuid list into per-item display rows for the batch-detail
     * expand. Pulls the uuid → completed-stream-id and uuid → failed-row-id
     * indexes via two MGETs (one round-trip each), then back-fills the
     * remaining uuids from the per-uuid pending hash.
     *
     * Per-item enrichment fields (class / attempts / chain / parent_uuid)
     * mirror what `recentCompleted()` rows carry on the Completed list, so
     * the batch-modal items can render with the same two-line + chips layout
     * the operator already knows from the main jobs table.
     *
     * The per-uuid `class`/`queued_at`/`failed_at` lookups for failed items
     * go through a single `whereIn` against `failed_jobs` so a 100-uuid batch
     * is one DB query, not 100. Completed items batch into one pipelined
     * XRANGE call across the aggregate `qi:completed` stream so a batch with
     * 100 completed members costs one Redis round-trip, not 100. Pending
     * items each cost one HGETALL — bounded by the per-batch uuid cap
     * (default 5000) and gated to the expanded row by render().
     *
     * @param  list<string>  $uuids
     * @return list<array{
     *   uuid: string,
     *   status: 'completed'|'failed'|'in_flight'|'pending',
     *   class: ?string,
     *   timestamp: ?int,
     *   stream_id: ?string,
     *   failed_id: ?int,
     *   attempts: int,
     *   chain: ?string,
     *   parent_uuid: ?string,
     * }>
     */
    public static function batchItems(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $completedKeys = array_map(
            static fn (string $u): string => KeyPrefix::make("uuid-completed:{$u}"),
            $uuids,
        );
        $failedKeys = array_map(
            static fn (string $u): string => KeyPrefix::make("uuid-failed:{$u}"),
            $uuids,
        );

        $completedVals = self::mget($redis, $completedKeys);
        $failedVals = self::mget($redis, $failedKeys);

        // First pass: collect failed-row ids so we can issue ONE failed_jobs
        // SELECT covering all failed uuids in this batch.
        $failedIds = [];
        $completedStreamIds = [];
        foreach ($uuids as $i => $uuid) {
            $raw = $failedVals[$i] ?? null;
            if (is_numeric($raw)) {
                $failedIds[$uuid] = (int) $raw;
            }

            $streamId = $completedVals[$i] ?? null;
            if (is_string($streamId) && $streamId !== '') {
                $completedStreamIds[$uuid] = $streamId;
            }
        }

        $failedMeta = BatchItemMeta::loadFailed(array_values($failedIds));
        $completedMeta = BatchItemMeta::loadCompleted($redis, $completedStreamIds);

        $rows = [];
        foreach ($uuids as $i => $uuid) {
            if ($uuid === '') {
                continue;
            }

            $streamId = $completedVals[$i] ?? null;
            if (is_string($streamId) && $streamId !== '') {
                $rows[] = self::completedItemRow($uuid, $streamId, $completedMeta[$streamId] ?? null);

                continue;
            }

            if (isset($failedIds[$uuid])) {
                $rows[] = self::failedItemRow($uuid, $failedIds[$uuid], $failedMeta[$failedIds[$uuid]] ?? null);

                continue;
            }

            $rows[] = self::pendingOrInFlightItemRow($redis, $uuid);
        }

        return $rows;
    }

    /**
     * @param  array{class: ?string, attempts: int, chain: ?string, parent_uuid: ?string, processed_at: ?int}|null  $meta
     * @return array{uuid: string, status: 'completed', class: ?string, timestamp: ?int, stream_id: string, failed_id: null, attempts: int, chain: ?string, parent_uuid: ?string}
     */
    private static function completedItemRow(string $uuid, string $streamId, ?array $meta): array
    {
        return [
            'uuid' => $uuid,
            'status' => 'completed',
            'class' => $meta['class'] ?? null,
            'timestamp' => $meta['processed_at'] ?? null,
            'stream_id' => $streamId,
            'failed_id' => null,
            'attempts' => $meta['attempts'] ?? 0,
            'chain' => $meta['chain'] ?? null,
            'parent_uuid' => $meta['parent_uuid'] ?? null,
        ];
    }

    /**
     * @param  array{class: ?string, failed_at: ?int, attempts: int, parent_uuid: ?string}|null  $meta
     * @return array{uuid: string, status: 'failed', class: ?string, timestamp: ?int, stream_id: null, failed_id: int, attempts: int, chain: ?string, parent_uuid: ?string}
     */
    private static function failedItemRow(string $uuid, int $failedId, ?array $meta): array
    {
        return [
            'uuid' => $uuid,
            'status' => 'failed',
            'class' => $meta['class'] ?? null,
            'timestamp' => $meta['failed_at'] ?? null,
            'stream_id' => null,
            'failed_id' => $failedId,
            'attempts' => $meta['attempts'] ?? 0,
            // Failed jobs don't carry a decoded chain summary on the index
            // row — the chain is buried inside the serialised command on
            // `failed_jobs.payload`. The failed-modal still surfaces it; the
            // batch item just doesn't pre-render a chain chip.
            'chain' => null,
            'parent_uuid' => $meta['parent_uuid'] ?? null,
        ];
    }

    /**
     * Check the pending hash for the uuid. Hash carries `state=in_flight`
     * once `RecordJobProcessing` has run, so a batched job that's actively
     * running renders as in_flight rather than pending. Class / queued_at /
     * started_at come from the same hash; null when pending.enabled=false
     * at queue time. `attempts` lands in the hash via `markInFlight` only,
     * so a still-pending job reports attempts=0.
     *
     * @return array{uuid: string, status: 'in_flight'|'pending', class: ?string, timestamp: ?int, stream_id: null, failed_id: null, attempts: int, chain: ?string, parent_uuid: ?string}
     */
    private static function pendingOrInFlightItemRow(Connection $redis, string $uuid): array
    {
        $pending = $redis->command('hgetall', [KeyPrefix::make("pending:{$uuid}")]);
        $hash = is_array($pending) ? $pending : [];

        $class = isset($hash['class']) && is_string($hash['class']) ? $hash['class'] : null;
        $queuedAt = isset($hash['queued_at']) && is_numeric($hash['queued_at']) ? (int) $hash['queued_at'] : null;
        $startedAt = isset($hash['started_at']) && is_numeric($hash['started_at']) ? (int) $hash['started_at'] : null;
        $isInFlight = isset($hash['state']) && $hash['state'] === 'in_flight';
        $attempts = isset($hash['attempts']) && is_numeric($hash['attempts']) ? (int) $hash['attempts'] : 0;
        $parentUuid = isset($hash['parent_uuid']) && is_string($hash['parent_uuid']) && $hash['parent_uuid'] !== ''
            ? $hash['parent_uuid']
            : null;

        return [
            'uuid' => $uuid,
            'status' => $isInFlight ? 'in_flight' : 'pending',
            'class' => $class,
            // Use `started_at` as the in-flight row's timestamp so the
            // batch item shows when the worker picked it up, not when it
            // was originally queued.
            'timestamp' => $isInFlight ? ($startedAt ?? $queuedAt) : $queuedAt,
            'stream_id' => null,
            'failed_id' => null,
            'attempts' => $attempts,
            // Pending items don't have a forward-chain summary yet — the
            // chain summary is written to the stream row on completion.
            'chain' => null,
            'parent_uuid' => $parentUuid,
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   name: ?string,
     *   total_jobs: int,
     *   pending_jobs: int,
     *   processed_jobs: int,
     *   failed_jobs: int,
     *   progress: int,
     *   created_at: ?CarbonInterface,
     *   finished_at: ?CarbonInterface,
     *   cancelled_at: ?CarbonInterface,
     * }
     */
    private static function projectBatch(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name === '' ? null : $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs,
            // `Batch::progress()` returns float on Laravel 11/12 and int on
            // Laravel 13 (round()'s default int_type changed). Cast so the
            // docblock's `progress: int` holds across the matrix and downstream
            // strict-equality consumers (Pest `toBe(67)` vs `toBe(67.0)`)
            // don't diverge by Laravel version.
            'progress' => (int) $batch->progress(),
            'created_at' => $batch->createdAt,
            'finished_at' => $batch->finishedAt,
            'cancelled_at' => $batch->cancelledAt,
        ];
    }

    /**
     * `Bus::findBatch` throws when no BatchRepository is bound (host app
     * doesn't use queue batching at all). Treat any error as "not found" so
     * the dashboard degrades to an empty list instead of breaking.
     */
    private static function findBatch(string $id): ?Batch
    {
        try {
            return Bus::findBatch($id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recent-batches section data. Shape mirrors `recentBatches()` rows
     * augmented with `is_open` and (when expanded) `items` populated via
     * `batchItems()` against the per-batch uuid list. No-ops to an empty
     * list when `batches.enabled = false` so the dashboard renders nothing.
     *
     * @return list<array<string, mixed>>
     */
    public static function sectionRows(QueueInsights $svc, string $expandedBatchId, ?string $connection = null): array
    {
        if (! Config::bool('batches.enabled', true)) {
            return [];
        }

        $rows = [];
        foreach ($svc->recentBatches(50, $connection) as $batch) {
            $isOpen = $expandedBatchId !== '' && $expandedBatchId === $batch['id'];

            $items = [];
            if ($isOpen) {
                $detail = $svc->batchDetail($batch['id'], $connection);
                if ($detail !== null) {
                    $items = self::batchItems($detail['uuids']);
                }
            }

            $rows[] = $batch + [
                'is_open' => $isOpen,
                'items' => $items,
            ];
        }

        return $rows;
    }

    /**
     * Single-batch row in the same shape `sectionRows()` returns, for the
     * dashboard's batch modal fallback when the open batch sits outside the
     * `batches.max_per_query` window. `recentBatches()` only loads the most
     * recent N — older batches whose retained reverse-uuid index still
     * points operators at them must still resolve, otherwise the chip lands
     * on the misleading "Batch no longer tracked" empty state.
     *
     * Returns null when batches are disabled, the id is empty, or the
     * BatchRepository row is genuinely gone.
     *
     * @return array<string, mixed>|null
     */
    public static function detailRow(string $batchId, ?string $connection = null): ?array
    {
        if (! Config::bool('batches.enabled', true) || $batchId === '') {
            return null;
        }

        $detail = self::batchDetail($batchId, $connection);
        if ($detail === null) {
            return null;
        }

        $items = self::batchItems($detail['uuids']);

        // Drop the uuid list (modal renders `items`, not raw uuids) and tag
        // the row open so it lines up with the `sectionRows()` shape callers
        // already key off of.
        unset($detail['uuids']);

        return $detail + [
            'is_open' => true,
            'items' => $items,
        ];
    }

    /**
     * MGET `qi:batch:uuid:{uuid}` for each uuid, returning a uuid → batchId
     * map. Bounded to the row count (typically <=50). Returns an empty map
     * when batches.enabled = false so the chip never renders.
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    public static function batchIdsForUuids(array $uuids): array
    {
        if (! Config::bool('batches.enabled', true) || $uuids === []) {
            return [];
        }

        $unique = array_values(array_unique(array_filter($uuids, static fn (string $u): bool => $u !== '')));
        if ($unique === []) {
            return [];
        }

        $keys = array_map(
            static fn (string $u): string => KeyPrefix::make("batch:uuid:{$u}"),
            $unique,
        );

        try {
            $values = self::mget(
                Redis::connection(Config::string('redis_connection', 'default')),
                $keys,
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($unique as $i => $uuid) {
            $val = $values[$i] ?? null;
            if (is_string($val) && $val !== '') {
                $out[$uuid] = $val;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private static function mget(Connection $redis, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // phpredis mGet() takes a single array arg; Predis auto-unwraps a single-array
        // arg via Command::normalizeArguments. Pass `[$keys]` to satisfy both.
        $values = $redis->command('mget', [$keys]);

        return is_array($values) ? array_values($values) : [];
    }
}
