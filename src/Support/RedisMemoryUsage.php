<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Sum total Redis memory consumed by this package's keyspace.
 *
 * Opt-in (`dashboard.redis_memory.enabled`). SCANs every key under
 * `key_prefix`, pipelines `MEMORY USAGE` per key, sums the bytes, and
 * caches the result for `dashboard.redis_memory.cache_ttl` seconds. The
 * dashboard polls every 10s but the recompute runs at most once per
 * minute by default — the SCAN + per-key `MEMORY USAGE` cost is paid
 * by the first poll after cache expiry, then served from cache.
 *
 * Cluster topologies are not yet supported (Laravel's `scan()` only
 * walks the current node, and iterating masters per driver doubles the
 * driver-branch surface). `totalBytes()` returns null on cluster so the
 * hero tile stays hidden instead of reporting partial data.
 *
 * Driver shim:
 *   - SCAN goes through the raw client (phpredis `rawCommand`, predis
 *     `executeRaw`) so neither driver's prefix processor mangles the
 *     MATCH pattern or the returned keys. The MATCH is composed
 *     manually from `database.redis.options.prefix` + `key_prefix`
 *     so the actual underlying keyspace is what we walk.
 *   - `MEMORY USAGE` likewise goes through the raw command path so
 *     phpredis's OPT_PREFIX doesn't double-prefix and Predis's command
 *     id mismatch (`memoryusage` vs `memory`) doesn't surface.
 */
final class RedisMemoryUsage
{
    private const string CACHE_KEY_PREFIX = 'queue-insights:redis-memory-bytes:';

    private const string LOCK_KEY_PREFIX = 'queue-insights:redis-memory-bytes:lock:';

    /**
     * Bytes consumed by every key matching the package prefix, or null
     * when the feature is disabled, the connection is a Redis Cluster,
     * or the computation failed (the throwable is logged at warning so
     * operators can find it without leaking to the dashboard UI).
     */
    public function totalBytes(): ?int
    {
        if (! Config::bool('dashboard.redis_memory.enabled', false)) {
            return null;
        }

        $connection = Config::string('redis_connection', 'default');
        $slot = hash('sha256', $connection . '|' . KeyPrefix::make(''));
        $cacheKey = self::CACHE_KEY_PREFIX . $slot;

        $cached = Cache::get($cacheKey);
        if (is_int($cached)) {
            return $cached;
        }

        $ttl = max(1, Config::int('dashboard.redis_memory.cache_ttl', 60));

        // Atomic recompute. Without a lock, every concurrent dashboard
        // poll after a cache miss races into its own SCAN + per-key
        // MEMORY USAGE walk — turning one operator's refresh into N
        // simultaneous keyspace sweeps. Non-blocking acquire so peers
        // that lose the race return null this tick and pick up the
        // warm cache on their next 10s poll.
        $store = Cache::getStore();
        if ($store instanceof LockProvider) {
            $lock = $store->lock(self::LOCK_KEY_PREFIX . $slot, $ttl);
            if ($lock->get() !== true) {
                return null;
            }

            try {
                return $this->refresh($cacheKey, $ttl, $connection);
            } finally {
                $lock->release();
            }
        }

        return $this->refresh($cacheKey, $ttl, $connection);
    }

    private function refresh(string $cacheKey, int $ttl, string $connection): ?int
    {
        try {
            $bytes = $this->compute();
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: redis-memory-usage compute failed', [
                'connection' => $connection,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if ($bytes === null) {
            return null;
        }

        Cache::put($cacheKey, $bytes, $ttl);

        return $bytes;
    }

    private function compute(): ?int
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        // Cluster: SCAN only walks the current node, so summing here
        // would silently under-report. Bail rather than serve a wrong
        // number — operators see the tile hidden instead of misleading.
        if ($redis instanceof PhpRedisClusterConnection || $redis instanceof PredisClusterConnection) {
            return null;
        }

        $pattern = RedisClientPrefix::resolve($redis) . KeyPrefix::make('') . '*';
        $cursor = '0';
        $total = 0;

        do {
            [$cursor, $keys] = $this->scanBatch($redis, $cursor, $pattern);

            if ($keys !== []) {
                $total += $this->sumMemoryUsage($redis, $keys);
            }
        } while ($cursor !== '0');

        return $total;
    }

    /**
     * One SCAN round-trip via the raw client to bypass driver prefix
     * processors. Both drivers report the next cursor as a string;
     * iteration stops when it returns to `'0'`. Keys come back with
     * the underlying client prefix intact — handed straight to
     * `MEMORY USAGE` below, which also bypasses prefix re-application.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function scanBatch(RedisConnection $redis, string $cursor, string $pattern): array
    {
        $reply = $this->rawCommand($redis, ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000']);

        if (! is_array($reply) || count($reply) < 2) {
            return ['0', []];
        }

        $next = is_scalar($reply[0]) ? (string) $reply[0] : '0';
        $rawKeys = is_array($reply[1]) ? $reply[1] : [];

        return [$next, array_values(array_filter($rawKeys, is_string(...)))];
    }

    /**
     * @param  list<string>  $keys
     */
    private function sumMemoryUsage(RedisConnection $redis, array $keys): int
    {
        $total = 0;

        // Serial round-trips, intentional. Both drivers' pipeline modes
        // route `MEMORY USAGE` through their key-prefix processor (Predis
        // `KeyPrefixProcessor`, phpredis `OPT_PREFIX`), which would
        // double-prefix the underlying keys SCAN just handed us. Predis
        // pipelines don't forward `executeRaw` cleanly without
        // hand-constructing `Predis\Command\RawCommand` objects — not
        // worth the complexity for an opt-in stat cached 60s. Operators
        // who enable this on a multi-thousand-key keyspace are warned by
        // the config comment.
        foreach ($keys as $key) {
            $reply = $this->rawCommand($redis, ['MEMORY', 'USAGE', $key]);

            if (is_numeric($reply)) {
                $total += (int) $reply;
            }
        }

        return $total;
    }

    /**
     * @param  list<string>  $args
     */
    private function rawCommand(RedisConnection $redis, array $args): mixed
    {
        $client = $redis->client();

        // phpredis: $client is `\Redis` (extension class, not autoloadable
        // in static analysis without ext-redis stubs). Predis: $client is
        // a `\Predis\Client` implementing `ClientInterface`. Detect by
        // method shape rather than class so the type system doesn't need
        // ext-redis present at analysis time.
        if (is_object($client) && method_exists($client, 'rawCommand')) {
            return $client->rawCommand(...$args);
        }

        if (is_object($client) && method_exists($client, 'executeRaw')) {
            return $client->executeRaw($args);
        }

        return null;
    }
}
