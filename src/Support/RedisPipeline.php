<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
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
 * Same naming style as `ZsetHead` — one driver shim per concern.
 */
final class RedisPipeline
{
    /**
     * The closure receives either a `\Predis\Pipeline\Pipeline` (predis
     * driver) or a `\Redis` instance in MULTI mode (phpredis); both expose
     * the same redis-verb surface via `__call`. The `@param` is annotated
     * as `Closure(\Predis\ClientInterface)` so PHPStan can resolve the
     * `@method hgetall/lrange/...` docblocks Predis ships — call sites
     * type the closure parameter as `mixed` so PHP doesn't enforce the
     * Predis-only contract at runtime when phpredis is the active driver.
     *
     * @param  Closure(ClientInterface): void  $callback
     * @return list<mixed>
     */
    public static function run(Connection $redis, Closure $callback): array
    {
        $results = $redis instanceof PhpRedisConnection
            ? $redis->pipeline($callback)
            : $redis->command('pipeline', [$callback]);

        return is_array($results) ? array_values($results) : [];
    }
}
