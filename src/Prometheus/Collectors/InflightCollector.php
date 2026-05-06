<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SnapshotPairs;

final class InflightCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.inflight_jobs', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $samples = [];
        foreach (SnapshotPairs::all() as $pair) {
            $count = $redis->command('zcard', [KeyPrefix::make("inflight-zset:{$pair['connection']}:{$pair['queue']}")]);

            $samples[] = new Sample(
                name: 'queue_insights_inflight_jobs',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: (float) (is_int($count) ? $count : 0),
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_inflight_jobs',
            type: 'gauge',
            help: 'Jobs currently being processed (zset cardinality).',
            samples: $samples,
        )];
    }
}
