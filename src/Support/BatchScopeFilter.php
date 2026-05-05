<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Scope-gating helpers for `BatchReader` under the per-connection v2-gap
 * routes. Lives outside `BatchReader` so the reader stays under PHPStan's
 * cognitive-complexity ceiling — the post-filter logic is independent of
 * the read path it gates.
 *
 * @internal
 */
final class BatchScopeFilter
{
    /**
     * Read `qi:batch:{id}:connection` and confirm it matches `$connection`.
     *
     * When the pointer is missing, fall back to scanning every configured
     * connection's per-connection roster. If the batch is present in some
     * other connection's roster → reject (the pointer aged out but the
     * batch still belongs to that other connection). If the batch is in
     * no per-connection roster at all → pass through as a legacy batch
     * stamped before v2-gap closure (rollout-window safety; new writes
     * always stamp the pointer + roster atomically via
     * `BatchClaimConnection.lua`).
     */
    public static function batchOwnedByConnection(Connection $redis, string $batchId, string $connection): bool
    {
        try {
            $pointer = $redis->command('get', [KeyPrefix::make("batch:{$batchId}:connection")]);
        } catch (Throwable) {
            return true;
        }

        if (is_string($pointer) && $pointer !== '') {
            return $pointer === $connection;
        }

        return self::ownerByRosterFallback($redis, $batchId, $connection);
    }

    /**
     * When the `:connection` pointer is gone, peek into every monitored
     * connection's roster zset. The batch is "owned" by `$connection` if
     * it's present in `$connection`'s roster and no foreign connection's
     * roster claims it; otherwise the batch belongs to the foreign
     * connection (the pointer aged out under it).
     *
     * No roster claims it at all → return true (legacy passthrough).
     */
    private static function ownerByRosterFallback(Connection $redis, string $batchId, string $connection): bool
    {
        foreach (ConfiguredConnections::all() as $name) {
            if ($name === $connection) {
                continue;
            }

            try {
                $rank = $redis->command('zrank', [KeyPrefix::make("batches:index:{$name}"), $batchId]);
            } catch (Throwable) {
                continue;
            }

            if ($rank !== null && $rank !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scope filter on a batch's uuid list. Reads each uuid's connection
     * from the dedicated `qi:batch-uuid-conn:{uuid}` side-key written by
     * `RecordJobQueued::writeBatchTracking`. The side-key lives for
     * `batches.ttl_seconds` (default 7 d) so the filter keeps working
     * after members have finished — the pending hash alone is not enough
     * because it gets deleted on JobProcessed / JobFailed.
     *
     * Uuids whose side-key is missing pass through (legacy batches +
     * members past `batches.ttl_seconds`); the roster-level gate is the
     * load-bearing protection.
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    public static function filterUuidsByConnection(Connection $redis, array $uuids, string $connection): array
    {
        if ($uuids === []) {
            return [];
        }

        $keys = array_map(
            static fn (string $u): string => KeyPrefix::make("batch-uuid-conn:{$u}"),
            $uuids,
        );

        try {
            // phpredis mGet() takes a single array; Predis auto-unwraps a
            // single-array argument. `[$keys]` works on both — same trick
            // BatchReader::mget uses.
            $values = $redis->command('mget', [$keys]);
        } catch (Throwable) {
            return $uuids;
        }

        if (! is_array($values)) {
            return $uuids;
        }

        $values = array_values($values);

        $out = [];
        foreach ($uuids as $i => $uuid) {
            $value = $values[$i] ?? null;
            if (! is_string($value) || $value === '' || $value === $connection) {
                $out[] = $uuid;
            }
        }

        return $out;
    }
}
