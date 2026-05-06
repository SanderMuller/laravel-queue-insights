<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SnapshotPairs;

/**
 * Boolean per-queue liveness — `live:depth:{c}:{q}` present → 1, else 0.
 * Pair with {@see SnapshotAgeCollector}; the age metric is omitted when
 * the key is absent so `_alive == 0` is the safe alerting target.
 */
final class SnapshotAliveCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.snapshot_alive', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $samples = [];
        foreach (SnapshotPairs::all() as $pair) {
            $exists = $redis->command('exists', [KeyPrefix::make("live:depth:{$pair['connection']}:{$pair['queue']}")]);

            $samples[] = new Sample(
                name: 'queue_insights_snapshot_alive',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: is_int($exists) && $exists > 0 ? 1.0 : 0.0,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_snapshot_alive',
            type: 'gauge',
            help: '1 when a recent snapshot exists for the queue, else 0.',
            samples: $samples,
        )];
    }
}
