<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;

/**
 * Per-(rule, taskKey) cooldown gate around scheduler alert dispatches.
 * Distinct from the queue-side `Alerts\Cooldown` so the two surfaces
 * can age independently — alerting on a queue depth doesn't suppress
 * a hung scheduled task.
 */
final class SchedulerCooldown
{
    public function acquire(string $rule, string $taskKey): bool
    {
        $ttl = Config::int('scheduler.alerts.cooldown_seconds', 900);
        $key = KeyPrefix::make("sched:alert:cooldown:{$rule}:{$taskKey}");

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

    private function redis(): Connection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
