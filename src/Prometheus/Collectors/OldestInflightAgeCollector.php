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
use SanderMuller\QueueInsights\Support\ZsetHead;

/**
 * Age in seconds of the oldest in-flight job per queue. Score on
 * `inflight-zset` is the worker `started_at` ts.
 */
final class OldestInflightAgeCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.oldest_inflight_age', true);
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
            $key = KeyPrefix::make("inflight-zset:{$pair['connection']}:{$pair['queue']}");
            $head = $redis->command('zrange', [$key, 0, 0, ['withscores' => true]]);
            $headPair = ZsetHead::firstMemberScore($head);

            $samples[] = new Sample(
                name: 'queue_insights_oldest_inflight_age_seconds',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: $headPair === null ? 0.0 : max(0.0, (float) $now - $headPair[1]),
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_oldest_inflight_age_seconds',
            type: 'gauge',
            help: 'Age in seconds of the oldest in-flight job. 0 when empty.',
            samples: $samples,
        )];
    }
}
