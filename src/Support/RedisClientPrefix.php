<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;

/**
 * Resolve `database.redis.{name}.options.prefix` (with fallback to
 * `database.redis.options.prefix`). Both Predis and phpredis apply this
 * to writes automatically; the value matters for code paths that either
 * strip it from a `KEYS` / `SCAN` reply or compose an underlying-keyspace
 * pattern that bypasses the driver's prefix processor.
 *
 * Reads from config rather than introspecting the client so the lookup
 * is driver-agnostic and statically typed.
 */
final class RedisClientPrefix
{
    public static function resolve(RedisConnection $redis): string
    {
        $name = $redis->getName() ?? Config::string('redis_connection', 'default');
        $prefix = config("database.redis.{$name}.options.prefix", config('database.redis.options.prefix'));

        return is_string($prefix) ? $prefix : '';
    }
}
