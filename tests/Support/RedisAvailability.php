<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

final class RedisAvailability
{
    public static function check(): bool
    {
        try {
            $response = Redis::connection('default')->command('ping', []);
        } catch (Throwable) {
            return false;
        }

        return $response === true || $response === 'PONG' || (is_object($response) && method_exists($response, 'getPayload'));
    }

    public static function flush(): void
    {
        Redis::connection('default')->command('flushdb', []);
    }
}
