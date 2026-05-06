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
 * `queue_insights_snapshot_errors_total` — monotonic counter from the
 * `snapshot-errors-total:{c}:{q}` INCR keys written by
 * `QueueInsightsSnapshotCommand::recordError`. Lives outside the
 * 10-min `snapshot:error:{c}:{q}` boolean so the boolean's TTL doesn't
 * fight Prometheus monotonicity.
 */
final class SnapshotErrorsCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.snapshot_errors_total', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $samples = [];
        foreach (SnapshotPairs::all() as $pair) {
            $value = $redis->command('get', [KeyPrefix::make("snapshot-errors-total:{$pair['connection']}:{$pair['queue']}")]);

            $samples[] = new Sample(
                name: 'queue_insights_snapshot_errors_total',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: is_numeric($value) ? (float) $value : 0.0,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_snapshot_errors_total',
            type: 'counter',
            help: 'Total snapshot driver errors per (connection, queue). Monotonic INCR — no TTL.',
            samples: $samples,
        )];
    }
}
