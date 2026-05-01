<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;

final class Cooldown
{
    /**
     * Acquire the cooldown slot for an issue. Returns true on first acquisition,
     * false when an existing slot is still held. Routes through `RedisEval` so
     * SET NX EX behaves identically on phpredis vs Predis.
     */
    public function acquire(Issue $issue): bool
    {
        $key = KeyPrefix::make($issue->cooldownKeySuffix());
        $ttl = Config::int('alerts.cooldown_seconds', 900);

        $result = RedisEval::exec(
            $this->redis(),
            "if redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX') then return 1 else return 0 end",
            1,
            $key,
            (string) Date::now()->getTimestamp(),
            (string) $ttl,
        );

        return $result === 1;
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
