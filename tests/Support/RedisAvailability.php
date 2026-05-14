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

    /**
     * True when a Redis Cluster connection is wired up (REDIS_CLUSTER_HOST
     * exported by the `cluster` CI lane) and reachable. Tests in the
     * `cluster` group gate on this and skip on the normal matrix lanes,
     * which never set the env var.
     */
    public static function clusterAvailable(): bool
    {
        $host = getenv('REDIS_CLUSTER_HOST');
        if (! is_string($host) || $host === '') {
            return false;
        }

        try {
            // Keyed probe — a GET routes by the key's slot, so it works on
            // a cluster connection without the node argument PING / INFO
            // would need. A missing key returns null; any transport or
            // cluster-formation failure throws.
            Redis::connection('cluster')->command('get', ['{qi-cluster-probe}']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
