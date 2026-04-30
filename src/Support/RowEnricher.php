<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Enriches the dashboard's `Recent completed` and `Recent failed` row
 * shapes with payload-derived fields. Lives in `Support/` so the per-row
 * payload decoding + batch-id reverse-lookup don't inflate the
 * `QueueInsightsDashboard` cognitive complexity budget.
 */
final class RowEnricher
{
    /**
     * @param  list<array<string, string>>  $recentCompleted
     * @return list<array<string, mixed>>
     */
    public static function completed(array $recentCompleted): array
    {
        $uuids = [];
        foreach ($recentCompleted as $row) {
            $u = $row['uuid'] ?? null;
            if (is_string($u) && $u !== '') {
                $uuids[] = $u;
            }
        }

        $batchIds = BatchReader::batchIdsForUuids($uuids);

        $parentUuids = [];
        foreach ($recentCompleted as $row) {
            $p = $row['parent_uuid'] ?? null;
            if (is_string($p) && $p !== '') {
                $parentUuids[] = $p;
            }
        }

        $parentClasses = $parentUuids === []
            ? []
            : ParentClassResolver::resolveMany($parentUuids);

        $rows = [];
        foreach ($recentCompleted as $row) {
            $id = $row['_id'] ?? '';
            $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : '';
            $chainEncoded = is_string($row['chain'] ?? null) ? $row['chain'] : '';
            $parentUuid = is_string($row['parent_uuid'] ?? null) && $row['parent_uuid'] !== ''
                ? $row['parent_uuid']
                : null;

            // Explicit assignment, NOT `$row + [...]` — the `+` operator
            // preserves existing keys, so it would leave `chain` as the raw
            // JSON string and the chip + decode-then-render path would break.
            $row['short_id'] = is_string($id) && $id !== '' ? mb_substr(explode('-', $id)[0], -9) : '';
            $row['batch_id'] = $uuid !== '' ? ($batchIds[$uuid] ?? null) : null;
            $row['chain'] = self::decodeChain($chainEncoded);
            $row['parent_uuid'] = $parentUuid;
            $row['parent_class'] = $parentUuid !== null ? ($parentClasses[$parentUuid] ?? null) : null;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Decode the JSON-encoded `chain` field written by `RecordJobProcessed`
     * (a list of `{class, connection, queue}` per chained job) back into the
     * same shape `SerializedCommandReader::extractChainContext` returns, so
     * downstream views see one uniform `chain` row field.
     *
     * `properties` is always empty here — the stream-stored chain is the
     * slim summary, no per-job user data. The failed-modal path re-extracts
     * from the full serialized payload to surface properties.
     *
     * @return array{
     *     next_class: string,
     *     remaining: int,
     *     chain_connection: ?string,
     *     chain_queue: ?string,
     *     jobs: list<array{class: string, connection: ?string, queue: ?string, properties: array<string, mixed>}>,
     * }|null
     */
    public static function decodeChain(string $encoded): ?array
    {
        if ($encoded === '') {
            return null;
        }

        $decoded = json_decode($encoded, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $jobs = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $class = $entry['class'] ?? null;
            if (! is_string($class)) {
                continue;
            }

            if ($class === '') {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            $jobs[] = [
                'class' => $class,
                'connection' => is_string($connection) && $connection !== '' ? $connection : null,
                'queue' => is_string($queue) && $queue !== '' ? $queue : null,
                'properties' => [],
            ];
        }

        if ($jobs === []) {
            return null;
        }

        return [
            'next_class' => $jobs[0]['class'],
            'remaining' => count($jobs),
            'chain_connection' => $jobs[0]['connection'],
            'chain_queue' => $jobs[0]['queue'],
            'jobs' => $jobs,
        ];
    }

    /**
     * @param  list<array<array-key, mixed>>  $recentFailed
     * @return list<array<string, mixed>>
     */
    public static function failed(array $recentFailed): array
    {
        $parentUuids = [];
        if (Config::bool('chain_lineage.enabled', true)) {
            $childUuids = [];
            foreach ($recentFailed as $row) {
                $u = $row['uuid'] ?? null;
                if (is_string($u) && $u !== '') {
                    $childUuids[] = $u;
                }
            }

            $parentUuids = self::lineageMany($childUuids);
        }

        $parentClasses = $parentUuids === []
            ? []
            : ParentClassResolver::resolveMany(array_values($parentUuids));

        $rows = [];
        foreach ($recentFailed as $row) {
            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $exception = is_string($row['exception'] ?? null) ? $row['exception'] : '';
            $exceptionFirst = explode("\n", $exception, 2)[0] ?? '';
            [$excClass, $excMessage] = self::splitExceptionHeader($exceptionFirst);

            $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : '';
            $parentUuid = $uuid !== '' ? ($parentUuids[$uuid] ?? null) : null;

            $rows[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                'uuid' => $uuid,
                'short_uuid' => $uuid !== '' ? mb_substr($uuid, -8) : '',
                'connection' => $row['connection'] ?? null,
                'queue' => $row['queue'] ?? null,
                'failed_at' => $row['failed_at'] ?? null,
                'display_name' => self::stringField($payload, 'displayName'),
                'attempts' => self::intField($payload, 'attempts'),
                'max_tries' => self::intField($payload, 'maxTries'),
                'exception_class' => $excClass,
                'exception_message' => $excMessage,
                'batch_id' => Config::bool('batches.enabled', true) ? self::batchId($payload) : null,
                'chain' => self::chainFromPayload($payload),
                'parent_uuid' => $parentUuid,
                'parent_class' => $parentUuid !== null ? ($parentClasses[$parentUuid] ?? null) : null,
            ];
        }

        return $rows;
    }

    /**
     * Bulk-load `qi:lineage:{child-uuid}` → `parent-uuid` for the failed
     * rows on the page. Routes through `ChainLineageStore` so the read
     * lands on the configured `chain_lineage.redis_connection` override
     * — the failed-list path was previously hard-coded to the primary
     * `redis_connection` and would lose attribution for hosts that
     * segregate lineage onto a separate Redis instance (codex review).
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private static function lineageMany(array $uuids): array
    {
        return (new ChainLineageStore())->readLineageMany($uuids);
    }

    /**
     * Read forward-chain context out of a decoded `failed_jobs.payload` —
     * `data.command` carries the same serialized job body that the worker
     * unserialized at run time. Encrypted jobs carry a base64 blob there
     * instead, which `SerializedCommandReader` returns null for.
     *
     * Returned `jobs[].properties` carries each chained job's user-bound
     * data (filtered framework internals removed) — failed-modal renders
     * them through the `serialized-properties` component for chain-detail
     * inspection.
     *
     * @return array{
     *     next_class: string,
     *     remaining: int,
     *     chain_connection: ?string,
     *     chain_queue: ?string,
     *     jobs: list<array{class: string, connection: ?string, queue: ?string, properties: array<string, mixed>}>,
     * }|null
     */
    public static function chainFromPayload(mixed $payload): ?array
    {
        if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data'])) {
            return null;
        }

        $command = $payload['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        return SerializedCommandReader::extractChainContext($command);
    }

    /**
     * Pluck `data.batchId` from the failed_jobs payload — plaintext alongside
     * the encrypted command body, so this works for `ShouldBeEncrypted` jobs.
     */
    private static function batchId(mixed $payload): ?string
    {
        if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data'])) {
            return null;
        }

        $batchId = $payload['data']['batchId'] ?? null;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }

    private static function stringField(mixed $payload, string $key): ?string
    {
        return is_array($payload) && isset($payload[$key]) && is_string($payload[$key])
            ? $payload[$key]
            : null;
    }

    private static function intField(mixed $payload, string $key): ?int
    {
        return is_array($payload) && isset($payload[$key]) && is_numeric($payload[$key])
            ? (int) $payload[$key]
            : null;
    }

    /**
     * "RuntimeException: Something broke" → ["RuntimeException", "Something broke"].
     *
     * @return array{0: string, 1: string}
     */
    private static function splitExceptionHeader(string $firstLine): array
    {
        $colon = strpos($firstLine, ':');
        if ($colon === false) {
            return [$firstLine, ''];
        }

        return [
            trim(substr($firstLine, 0, $colon)),
            trim(substr($firstLine, $colon + 1)),
        ];
    }
}
