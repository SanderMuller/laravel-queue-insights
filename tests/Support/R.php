<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\RedisEval;

/**
 * Test-side Redis helper. Replaces `(int) $r->command('get', [$key])` (which
 * casts `mixed` and trips PHPStan at max level) with typed getters.
 */
final class R
{
    public static function conn(string $name = 'default'): Connection
    {
        return Redis::connection($name);
    }

    public static function int(string $cmd, int|string ...$args): int
    {
        $value = self::dispatch($cmd, $args);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function str(string $cmd, int|string ...$args): ?string
    {
        $value = self::dispatch($cmd, $args);

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    public static function float(string $cmd, int|string ...$args): float
    {
        $value = self::dispatch($cmd, $args);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return list<mixed>|mixed
     */
    public static function raw(string $cmd, int|string ...$args): mixed
    {
        return self::dispatch($cmd, $args);
    }

    /**
     * @param  array<int|string, int|string>  $args
     */
    private static function dispatch(string $cmd, array $args): mixed
    {
        if ($cmd === 'eval') {
            $script = (string) ($args[0] ?? '');
            $numKeys = (int) ($args[1] ?? 0);
            $rest = array_slice($args, 2);
            $restStrings = array_map(static fn (int|string $v): string => (string) $v, $rest);

            return RedisEval::exec(self::conn(), $script, $numKeys, ...$restStrings);
        }

        return self::conn()->command($cmd, $args);
    }
}
