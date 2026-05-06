<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SnapshotPairs;

final class QueueDepthCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.queue_depth', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $samples = [];
        foreach (SnapshotPairs::all() as $pair) {
            $value = $redis->command('get', [KeyPrefix::make("live:depth:{$pair['connection']}:{$pair['queue']}")]);
            if (! is_numeric($value)) {
                continue;
            }

            $samples[] = new Sample(
                name: 'queue_insights_queue_depth',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: (float) $value,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_queue_depth',
            type: 'gauge',
            help: 'Current depth of the queue (live snapshot value).',
            samples: $samples,
        )];
    }
}
