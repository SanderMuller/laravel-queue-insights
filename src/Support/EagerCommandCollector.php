<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection;
use Predis\ClientInterface;

/**
 * Eager stand-in for a pipeline object, used on Redis Cluster connections.
 *
 * phpredis's `RedisCluster` client exposes no `pipeline()` method, and
 * Laravel's `PhpRedisClusterConnection` inherits `PhpRedisConnection::pipeline()`
 * unchanged — so pipelining a clustered connection fatals with an undefined
 * method `Error`. This collector mimics the pipeline-callback contract:
 * callers queue commands via method calls exactly as they would on a real
 * pipeline object, but each call executes immediately against the underlying
 * connection and the reply is appended to an ordered list. `RedisPipeline::run`
 * hands back that list, so every call site sees the identical positional-reply
 * shape regardless of whether the active connection pipelines or not.
 *
 * Trade-off: N round-trips instead of one batched flush. Acceptable on
 * cluster — the cluster client routes each single-key command to its owning
 * node (following MOVED), which one cross-slot pipeline could never do.
 *
 * `@mixin ClientInterface` — the `__call` below forwards every redis verb to
 * the connection, so to PHPStan this object has the same method surface a
 * Predis pipeline / client does. Without it, `RedisPipeline::run`'s callback
 * type (`Closure(ClientInterface|EagerCommandCollector): void`) would make
 * every `$pipe->zcard(...)` etc. at the call sites an undefined-method error.
 *
 * @mixin ClientInterface
 */
final class EagerCommandCollector
{
    /** @var list<mixed> */
    private array $results = [];

    public function __construct(private readonly Connection $redis) {}

    /**
     * Pipeline callbacks are typed `: void` and discard the per-command
     * return value — the reply is captured in `$results`, not handed back.
     *
     * @param  list<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): null
    {
        $this->results[] = $this->redis->command($method, $arguments);

        return null;
    }

    /**
     * Replies in the order their commands were queued — same contract as
     * the numerically-indexed list `RedisPipeline::run` returns for the
     * native-pipeline path.
     *
     * @return list<mixed>
     */
    public function results(): array
    {
        return $this->results;
    }
}
