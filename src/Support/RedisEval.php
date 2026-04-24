<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Driver-agnostic EVAL dispatcher.
 *
 * phpredis's native signature is `eval(string $script, array $keysAndArgs, int $numKeys)` —
 * three positional args. Predis forwards variadic `eval(script, numKeys, ...keysAndArgs)`.
 * Calling `$connection->command('eval', [...])` bypasses Laravel's PhpRedisConnection::eval
 * wrapper and hits the extension method directly, which then blows up on phpredis when more
 * than three positional args are passed. Routing through this helper keeps one call site.
 */
final class RedisEval
{
    public static function exec(RedisConnection $redis, string $script, int $numKeys, string ...$keysAndArgs): mixed
    {
        if ($redis instanceof PhpRedisConnection) {
            return $redis->command('eval', [$script, $keysAndArgs, $numKeys]);
        }

        return $redis->command('eval', [$script, $numKeys, ...$keysAndArgs]);
    }
}
