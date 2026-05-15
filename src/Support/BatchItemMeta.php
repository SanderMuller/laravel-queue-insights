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
     * Pipelined XRANGE across the aggregate `qi:completed` stream — one
     * Redis round-trip total for the whole batch. Returns
     * `streamId => meta`, with missing / aged-out entries simply absent so
     * callers can `?? null` per uuid.
     *
     * @param  array<string, string>  $streamIdsByUuid  uuid => streamId
     * @return array<string, array{class: ?string, attempts: int, chain: ?string, parent_uuid: ?string, processed_at: ?int}>
     */
    public static function loadCompleted(Connection $redis, array $streamIdsByUuid): array
    {
        if ($streamIdsByUuid === []) {
            return [];
        }

        $streamIds = array_values(array_unique($streamIdsByUuid));
        $streamKey = KeyPrefix::make('completed');

        $results = RedisPipeline::run($redis, static function (mixed $client) use ($streamKey, $streamIds): void {
            foreach ($streamIds as $id) {
                // XRANGE with the same id for start + end returns 0 or 1
                // entries — the targeted lookup. Cluster-safe via the
                // EagerCommandCollector path (single key per call).
                $client->xrange($streamKey, $id, $id);
            }
        });

        $out = [];
        foreach ($streamIds as $idx => $id) {
            $entries = $results[$idx] ?? null;
            if (! is_array($entries) || $entries === []) {
                continue;
            }

            // phpredis returns `{id => {field => value}}`, predis returns
            // a list of `[id, fields]` pairs. Both shapes collapse to the
            // first entry's field map below.
            $first = $entries[array_key_first($entries)] ?? null;
            $fields = is_array($first) ? $first : null;
            if ($fields === null) {
                continue;
            }

            $out[$id] = self::completedFromFields($fields);
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
     * @return array{class: ?string, attempts: int, chain: ?string, parent_uuid: ?string, processed_at: ?int}
     */
    private static function completedFromFields(array $fields): array
    {
        return [
            'class' => isset($fields['class']) && is_string($fields['class']) ? $fields['class'] : null,
            'attempts' => isset($fields['attempts']) && is_numeric($fields['attempts']) ? (int) $fields['attempts'] : 0,
            'chain' => isset($fields['chain']) && is_string($fields['chain']) && $fields['chain'] !== ''
                ? $fields['chain']
                : null,
            'parent_uuid' => isset($fields['parent_uuid']) && is_string($fields['parent_uuid']) && $fields['parent_uuid'] !== ''
                ? $fields['parent_uuid']
                : null,
            'processed_at' => isset($fields['processed_at']) && is_numeric($fields['processed_at']) ? (int) $fields['processed_at'] : null,
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
