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
 * Pending jobs available right now — `available_at <= now`. Mirrors
 * Spatie's split between "pending" and "delayed"; the underlying
 * `pending-zset` carries both, scored by `available_at`.
 */
final class PendingCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.pending_jobs', true);
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
                '-inf',
                (string) $now,
            ]);

            $samples[] = new Sample(
                name: 'queue_insights_pending_jobs',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: (float) (is_int($count) ? $count : 0),
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_pending_jobs',
            type: 'gauge',
            help: 'Pending jobs whose available_at has passed (runnable now).',
            samples: $samples,
        )];
    }
}
