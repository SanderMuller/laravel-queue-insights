<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed store for the chain-claim list and the interim lineage hash.
 *
 * Two key families:
 *   `qi:chain-claim:{conn}:{queue}:{class}:{fp}` — list of parent UUIDs
 *       (LPUSH at write time, RPOP at read time, FIFO).
 *   `qi:lineage:{child-uuid}` — single value `parent-uuid`, copied into the
 *       durable record by the next listener that owns persistence.
 *
 * Spec §2.1 — list semantics chosen over a single-key SETEX so two parents
 * with identical chain shape don't overwrite each other; the worst case is
 * "concurrent identical-shape parents attributed in dispatch order".
 */
final class ChainLineageStore
{
    public function pushClaim(string $key, string $parentUuid, int $ttlSeconds): void
    {
        if ($parentUuid === '' || $ttlSeconds <= 0) {
            return;
        }

        RedisEval::exec(
            $this->connection(),
            LuaScripts::pushChainClaim(),
            1,
            $key,
            $parentUuid,
            (string) $ttlSeconds,
        );
    }

    /**
     * RPOP from the FIFO list. Returns null when the list is empty / missing
     * (root dispatch, expired ticket, encrypted parent, etc).
     */
    public function popClaim(string $key): ?string
    {
        $result = $this->connection()->command('rpop', [$key]);

        return is_string($result) && $result !== '' ? $result : null;
    }

    public function writeLineage(string $childUuid, string $parentUuid, int $ttlSeconds): void
    {
        if ($childUuid === '' || $parentUuid === '' || $ttlSeconds <= 0) {
            return;
        }

        $this->connection()->command('setex', [
            $this->lineageKey($childUuid),
            $ttlSeconds,
            $parentUuid,
        ]);
    }

    public function readLineage(string $childUuid): ?string
    {
        if ($childUuid === '') {
            return null;
        }

        $result = $this->connection()->command('get', [$this->lineageKey($childUuid)]);

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * Batched lookup for the failed-rows enrichment path. Reads
     * `qi:lineage:{uuid}` for every uuid in one MGET round-trip on the
     * `chain_lineage.redis_connection` override (or the package default).
     * Centralising the connection resolution here keeps `RowEnricher`
     * from drifting back to the primary `redis_connection` when a host
     * runs lineage on a separate Redis instance (codex review).
     *
     * @param  list<string>  $childUuids
     * @return array<string, string>
     */
    public function readLineageMany(array $childUuids): array
    {
        $unique = array_values(array_unique(array_filter(
            $childUuids,
            static fn (string $u): bool => $u !== '',
        )));

        if ($unique === []) {
            return [];
        }

        $keys = array_map($this->lineageKey(...), $unique);

        try {
            $values = $this->connection()->command('mget', [$keys]);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($unique as $i => $uuid) {
            $value = $values[$i] ?? null;
            if (is_string($value) && $value !== '') {
                $out[$uuid] = $value;
            }
        }

        return $out;
    }

    public function forgetLineage(string $childUuid): void
    {
        if ($childUuid === '') {
            return;
        }

        $this->connection()->command('del', [$this->lineageKey($childUuid)]);
    }

    public function lineageKey(string $childUuid): string
    {
        return KeyPrefix::make("lineage:{$childUuid}");
    }

    private function connection(): RedisConnection
    {
        // Prefer the per-feature override; fall back to the package's primary
        // redis_connection. Both arrive as connection NAMES (config/database.php
        // → redis.connections), not raw client objects.
        $override = Config::string('chain_lineage.redis_connection', '');
        $name = $override !== '' ? $override : Config::string('redis_connection', 'default');

        return Redis::connection($name);
    }
}
