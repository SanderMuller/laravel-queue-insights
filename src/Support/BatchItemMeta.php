<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk meta-loaders for the batch-modal item list. Pulls per-item
 * enrichment fields (class, attempts, chain, parent_uuid) for completed
 * stream entries and failed_jobs rows in one round-trip each, so the
 * `BatchReader::batchItems()` projection can render the same two-line +
 * chips layout the main Completed list already uses without ballooning
 * its read budget.
 *
 * Lives outside `BatchReader` only to keep that class under its
 * cognitive-complexity budget — call sites read as if they were on
 * `BatchReader` itself.
 *
 * @internal
 */
final class BatchItemMeta
{
    /**
     * Bulk-fetch completed-stream entries via a single EVAL — one round-trip
     * total regardless of batch size or Redis topology. The previous
     * implementation pipelined N XRANGE calls, which silently fanned out
     * to N synchronous round-trips on cluster connections (RedisPipeline
     * downgrades to `EagerCommandCollector` for cluster — see its docblock).
     *
     * Lua returns a flat `{field, value, field, value, ...}` list per
     * stream id (predis + phpredis both deliver it as a numerically
     * indexed list), which `flatListToMap()` folds back into an assoc
     * field map for `completedFromFields()`.
     *
     * @param  array<string, string>  $streamIdsByUuid  uuid => streamId
     * @return array<string, array{class: ?string, attempts: int, chain: ?array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string, jobs: list<array<string, mixed>>}, parent_uuid: ?string, processed_at: ?int}>
     */
    public static function loadCompleted(Connection $redis, array $streamIdsByUuid): array
    {
        if ($streamIdsByUuid === []) {
            return [];
        }

        // Filter to well-formed stream IDs (`<ms>-<seq>`). A single corrupt
        // or stale `uuid-completed:*` pointer would otherwise make Redis
        // throw inside the EVAL — taking down the whole batch render with
        // it. Mirrors `loadFailed`'s fail-soft posture: skip bad pointers,
        // return enrichment for the rest.
        $streamIds = array_values(array_unique(
            array_filter(
                $streamIdsByUuid,
                static fn (string $id): bool => preg_match('/^\d+-\d+$/', $id) === 1,
            ),
        ));

        if ($streamIds === []) {
            return [];
        }

        try {
            $reply = RedisEval::exec(
                $redis,
                LuaScripts::batchFetchCompletedMeta(),
                1,
                KeyPrefix::make('completed'),
                ...$streamIds,
            );
        } catch (Throwable) {
            // Any Redis error (NOSCRIPT, OOM, IO, malformed reply, …) falls
            // back to "no enrichment" — the batch modal degrades to the
            // status-appropriate placeholder + UUID row instead of throwing
            // into the dashboard renderer.
            return [];
        }

        if (! is_array($reply)) {
            return [];
        }

        $out = [];
        foreach ($streamIds as $idx => $id) {
            $flat = $reply[$idx] ?? null;
            if (! is_array($flat)) {
                continue;
            }

            if ($flat === []) {
                continue;
            }

            $out[$id] = self::completedFromFields(self::flatListToMap($flat));
        }

        return $out;
    }

    /**
     * Fold a Redis Lua flat `{field, value, field, value, ...}` list into a
     * `{field => value}` map. Non-string keys are silently dropped — Lua
     * tables coming back as flat lists are always string-keyed in Redis's
     * reply protocol, but the guard catches a misbehaving custom driver
     * returning mixed-type entries.
     *
     * @param  array<int|string, mixed>  $flat
     * @return array<string, mixed>
     */
    private static function flatListToMap(array $flat): array
    {
        $values = array_values($flat);
        $out = [];
        $count = count($values);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $key = $values[$i];
            if (! is_string($key)) {
                continue;
            }

            $out[$key] = $values[$i + 1];
        }

        return $out;
    }

    /**
     * Load class + failed_at + attempts for the given failed_jobs row ids in
     * one SELECT. Lineage isn't on the failed-jobs payload — the
     * failed-modal fetches `qi:lineage:{uuid}` separately, so batch items
     * leave `parent_uuid` null here.
     *
     * @param  list<int>  $ids
     * @return array<int, array{class: ?string, failed_at: ?int, attempts: int, parent_uuid: ?string}>
     */
    public static function loadFailed(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $rows = DB::table('failed_jobs')->whereIn('id', $ids)->get(['id', 'payload', 'failed_at']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $rowId = $row->id ?? null;
            if (! is_numeric($rowId)) {
                continue;
            }

            $payload = is_string($row->payload ?? null) ? json_decode($row->payload, true) : null;
            $failedAtRaw = $row->failed_at ?? null;

            $out[(int) $rowId] = self::failedFromPayload(
                is_array($payload) ? $payload : null,
                is_string($failedAtRaw) || is_numeric($failedAtRaw) ? (string) $failedAtRaw : null,
            );
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $fields  stream-entry field map
     * @return array{class: ?string, attempts: int, chain: ?array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string, jobs: list<array<string, mixed>>}, parent_uuid: ?string, processed_at: ?int}
     */
    private static function completedFromFields(array $fields): array
    {
        // `RecordJobProcessed` writes `processed_at` as ISO-8601 (the same
        // shape the details-modal qi-time component consumes). Batch items
        // need an epoch int because the modal template's `is_int($ts)`
        // gate hands the value to qi-time; parse here so a completed batch
        // row shows when it finished, mirroring the pending/in-flight/failed
        // statuses that already carry an int timestamp.
        $processedAt = null;
        $processedAtRaw = $fields['processed_at'] ?? null;
        if (is_string($processedAtRaw) && $processedAtRaw !== '') {
            $ts = strtotime($processedAtRaw);
            $processedAt = $ts === false ? null : $ts;
        } elseif (is_numeric($processedAtRaw)) {
            $processedAt = (int) $processedAtRaw;
        }

        // Decode the chain summary at hydrate time so the batch-modal
        // template doesn't `json_decode()` per row on every 10s poll for
        // a large open batch. Result is the same typed shape the template
        // already consumed via `RowEnricher::decodeChain`.
        $chainRaw = isset($fields['chain']) && is_string($fields['chain']) ? $fields['chain'] : '';
        $chain = $chainRaw !== '' ? RowEnricher::decodeChain($chainRaw) : null;

        return [
            'class' => isset($fields['class']) && is_string($fields['class']) ? $fields['class'] : null,
            'attempts' => isset($fields['attempts']) && is_numeric($fields['attempts']) ? (int) $fields['attempts'] : 0,
            'chain' => $chain,
            'parent_uuid' => isset($fields['parent_uuid']) && is_string($fields['parent_uuid']) && $fields['parent_uuid'] !== ''
                ? $fields['parent_uuid']
                : null,
            'processed_at' => $processedAt,
        ];
    }

    /**
     * @param  array<int|string, mixed>|null  $payload  decoded `failed_jobs.payload`
     * @return array{class: ?string, failed_at: ?int, attempts: int, parent_uuid: ?string}
     */
    private static function failedFromPayload(?array $payload, ?string $failedAtRaw): array
    {
        $class = is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
            ? $payload['displayName']
            : null;

        // `failed_jobs.payload.attempts` is Laravel's snapshot at the moment
        // the job was archived. Used by the batch-modal retry chip so an
        // operator can spot retried-then-failed jobs without opening the
        // failed-modal.
        $attempts = is_array($payload) && isset($payload['attempts']) && is_numeric($payload['attempts'])
            ? (int) $payload['attempts']
            : 0;

        $failedAt = null;
        if ($failedAtRaw !== null) {
            $ts = strtotime($failedAtRaw);
            $failedAt = $ts === false ? null : $ts;
        }

        return [
            'class' => $class,
            'failed_at' => $failedAt,
            'attempts' => $attempts,
            'parent_uuid' => null,
        ];
    }
}
