<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SnapshotPairs;

/**
 * Delayed jobs not yet runnable — `available_at > now`. Same zset as
 * {@see PendingCollector}; complementary score range so both metrics
 * partition the zset cleanly.
 */
final class DelayedCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.delayed_jobs', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $now = Date::now()->getTimestamp();

        $samples = [];
        foreach (SnapshotPairs::all() as $pair) {
            $count = $redis->command('zcount', [
                KeyPrefix::make("pending-zset:{$pair['connection']}:{$pair['queue']}"),
                '(' . $now,
                '+inf',
            ]);

            $samples[] = new Sample(
                name: 'queue_insights_delayed_jobs',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: (float) (is_int($count) ? $count : 0),
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_delayed_jobs',
            type: 'gauge',
            help: 'Delayed jobs whose available_at is still in the future.',
            samples: $samples,
        )];
    }
}
