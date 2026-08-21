<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Redis;

/** Enriches Recent completed / Recent failed rows with payload-derived fields. */
final class RowEnricher
{
    /**
     * @param  list<array<string, string>>  $recentCompleted
     * @return list<array<string, mixed>>
     */
    public static function completed(array $recentCompleted): array
    {
        $uuids = self::collectStringField($recentCompleted, 'uuid');
        $batchIds = BatchReader::batchIdsForUuids($uuids);

        $parentUuids = self::collectStringField($recentCompleted, 'parent_uuid');
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
            // Initiator origin + call site — RecordJobProcessed copied both
            // onto the completed-stream entry, so they're already on `$row`.
            // Normalise to a non-empty string or null so downstream views
            // get a uniform shape regardless of whether the field was
            // written.
            $origin = $row['origin'] ?? null;
            $row['origin'] = is_string($origin) && $origin !== '' ? $origin : null;
            $callSite = $row['call_site'] ?? null;
            $row['call_site'] = is_string($callSite) && $callSite !== '' ? $callSite : null;

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
        $childUuids = self::collectStringField($recentFailed, 'uuid');
        $parentUuids = Config::bool('chain_lineage.enabled', true)
            ? self::lineageMany($childUuids)
            : [];
        $parentClasses = $parentUuids === []
            ? []
            : ParentClassResolver::resolveMany(array_values($parentUuids));
        $runtimes = self::failedRuntimesMany($childUuids);

        $rows = [];
        foreach ($recentFailed as $row) {
            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $exception = is_string($row['exception'] ?? null) ? $row['exception'] : '';
            $exceptionFirst = explode("\n", $exception, 2)[0] ?? '';
            [$excClass, $excMessage] = self::splitExceptionHeader($exceptionFirst);

            $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : '';
            $parentUuid = $uuid !== '' ? ($parentUuids[$uuid] ?? null) : null;

            $displayName = self::stringField($payload, 'displayName');

            // failed_jobs stores the raw queue — an SQS queue URL on Vapor
            // (`https://sqs.{region}.amazonaws.com/{acct}/{name}`). Canonicalise
            // it so failed rows render the short queue key (`staging_default`)
            // like completed/pending rows. `from()` — NOT `fromOrDefault()`:
            // a stored empty queue stays empty (→ null), never invents the
            // connection default, which would otherwise let the deep-link
            // scope guard admit a row `applyFailedJobFilters()` would not.
            $connectionRaw = is_string($row['connection'] ?? null) ? $row['connection'] : '';
            $queueRaw = is_string($row['queue'] ?? null) ? $row['queue'] : '';
            $connection = ConnectionAlias::canonical($connectionRaw);
            $queueKey = $queueRaw !== '' ? CanonicalQueueKey::forConnection($queueRaw, $connection) : null;

            $rows[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                'uuid' => $uuid,
                'short_uuid' => $uuid !== '' ? mb_substr($uuid, -8) : '',
                'connection' => $connection !== '' ? $connection : null,
                'queue' => $queueKey,
                'failed_at' => $row['failed_at'] ?? null,
                // `class` mirrors the completed-row contract so downstream
                // filters (DashboardData::buildSilencedListings) can read
                // the same key on both row shapes. `display_name` is kept
                // for templates that already bind to it.
                'class' => $displayName,
                'display_name' => $displayName,
                'attempts' => self::intField($payload, 'attempts'),
                'max_tries' => self::intField($payload, 'maxTries'),
                'duration_ms' => $uuid !== '' ? ($runtimes[$uuid] ?? null) : null,
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
     * Collect the non-empty string values of `$field` across `$rows`.
     * Shared by the completed + failed enrichment fan-outs so the
     * foreach + type-guard pattern lives in one place.
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<string>
     */
    private static function collectStringField(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Bulk-load `failed-runtime:{uuid}` → runtime_ms for the failed page.
     * RecordJobFailed writes the side-key with a 30 d TTL when `start:{uuid}`
     * (the JobProcessing-stamped microtime float) was readable; aged-out
     * runs return null and the row renders `—`.
     *
     * @param  list<string>  $uuids
     * @return array<string, int>
     */
    private static function failedRuntimesMany(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $keys = array_map(static fn (string $u): string => KeyPrefix::make("failed-runtime:{$u}"), $uuids);
        $values = Redis::connection(Config::string('redis_connection', 'default'))->command('mget', [$keys]);
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($uuids as $i => $u) {
            $v = $values[$i] ?? null;
            if (is_numeric($v)) {
                $out[$u] = (int) $v;
            }
        }

        return $out;
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
