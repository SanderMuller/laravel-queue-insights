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
 * Age in seconds of the oldest *runnable* pending job per queue —
 * `now - min(score)` over `pending-zset` scored at or below `now`.
 * Emits 0 when the queue is empty (or no head ≤ now is present).
 */
final class OldestPendingAgeCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.oldest_pending_age', true);
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
            $key = KeyPrefix::make("pending-zset:{$pair['connection']}:{$pair['queue']}");
            $head = $redis->command('zrangebyscore', [$key, '-inf', (string) $now, ['limit' => [0, 1], 'withscores' => true]]);
            $headPair = ZsetHead::firstMemberScore($head);

            $samples[] = new Sample(
                name: 'queue_insights_oldest_pending_age_seconds',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: $headPair === null ? 0.0 : max(0.0, (float) $now - $headPair[1]),
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_oldest_pending_age_seconds',
            type: 'gauge',
            help: 'Age in seconds of the oldest runnable pending job. 0 when empty.',
            samples: $samples,
        )];
    }
}
