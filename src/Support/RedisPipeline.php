<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Predis\ClientInterface;

/**
 * Driver-agnostic wrapper around `Connection::pipeline()` that always
 * returns a numerically-indexed `list<mixed>` of per-command replies in
 * the same order they were queued.
 *
 * phpredis declares a typed `pipeline(?callable)` method on its
 * `PhpRedisConnection`; Predis only exposes it through the magic
 * `__call → command('pipeline', …)` path. The two routes are not
 * interchangeable — calling `command('pipeline', [$callback])` against
 * a phpredis Connection raises an `ArgumentCountError` (`Redis::pipeline()
 * expects exactly 0 arguments`). Branch on the connection type and
 * normalise the reply via `array_values()` so callers can always index
 * by integer offset.
 *
 * Redis Cluster is a third case. phpredis's `RedisCluster` client exposes
 * no `pipeline()` method at all, and `PhpRedisClusterConnection` inherits
 * `PhpRedisConnection::pipeline()` unchanged — so the phpredis branch below
 * would fatal with an undefined-method `Error` on a clustered connection.
 * Cluster connections route through `EagerCommandCollector` instead: each
 * queued command executes immediately, letting the cluster client send
 * every single-key command to its owning node (CROSSSLOT can only reject a
 * *single* multi-key command, never a sequence of single-key ones). The
 * reply-list shape is identical, so call sites are unaffected.
 *
 * Same naming style as `ZsetHead` — one driver shim per concern.
 */
final class RedisPipeline
{
    /**
     * The closure receives either a `\Predis\Pipeline\Pipeline` (predis
     * driver), a `\Redis` instance in MULTI mode (phpredis), or an
     * `EagerCommandCollector` (any cluster connection); all expose the
     * same redis-verb surface via `__call`. `ClientInterface` stays in the
     * `@param` union so PHPStan can resolve the `@method hgetall/lrange/...`
     * docblocks Predis ships — call sites type the closure parameter as
     * `mixed` so PHP doesn't enforce the contract at runtime whichever
     * driver / topology is active.
     *
     * @param  Closure(ClientInterface|EagerCommandCollector): void  $callback
     * @return list<mixed>
     */
    public static function run(Connection $redis, Closure $callback): array
    {
        // Cluster check first — PhpRedisClusterConnection extends
        // PhpRedisConnection, so the phpredis branch below would swallow it.
        if ($redis instanceof PhpRedisClusterConnection || $redis instanceof PredisClusterConnection) {
            $collector = new EagerCommandCollector($redis);
            $callback($collector);

            return $collector->results();
        }

        $results = $redis instanceof PhpRedisConnection
            ? $redis->pipeline($callback)
            : $redis->command('pipeline', [$callback]);

        return is_array($results) ? array_values($results) : [];
    }
}
