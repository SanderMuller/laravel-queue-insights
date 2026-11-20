<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Closure;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Log;
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
    /**
     * Run a lineage read or write from a listener without letting a failure
     * of the lineage Redis (`chain_lineage.redis_connection` can point at its
     * own instance) cost the listener's other writes: log, report, and
     * continue with the parent unresolved.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T|null
     */
    public static function bestEffort(string $listener, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $throwable) {
            Log::warning("queue-insights: {$listener} chain lineage failed", [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            resolve(ListenerFailureReporter::class)->report($listener, $throwable);

            return null;
        }
    }

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

    /**
     * True when lineage keys live on the package's primary Redis connection,
     * so the job listeners can queue lineage commands into their own
     * pipeline instead of calling this store.
     */
    public function usesPrimaryConnection(): bool
    {
        $override = Config::string('chain_lineage.redis_connection', '');

        return $override === '' || $override === Config::string('redis_connection', 'default');
    }

    public function lineageKey(string $childUuid): string
    {
        return KeyPrefix::make("lineage:{$childUuid}");
    }

    private function connection(): RedisConnection
    {
        // Prefer the per-feature override; fall back to the package's primary
        // redis_connection. Both arrive as connection NAMES (config/database.php
        // → `redis`), not raw client objects.
        $override = Config::string('chain_lineage.redis_connection', '');
        $name = $override !== '' ? $override : Config::string('redis_connection', 'default');

        return Redis::connection($name);
    }
}
