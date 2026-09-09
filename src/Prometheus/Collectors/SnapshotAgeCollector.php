<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SnapshotCadence;
use SanderMuller\QueueInsights\Support\SnapshotPairs;

/**
 * Age of the most recent snapshot per queue — `now` minus the capture
 * timestamp the snapshot command stamps into `live:at:{c}:{q}` (same TTL
 * as the live metric keys, see {@see SnapshotCadence}).
 *
 * The metric is **omitted** when the key is absent so a dead snapshot
 * loop reads as `absent(queue_insights_snapshot_age_seconds)`, not as
 * "0 seconds — looks fresh". Pair with {@see SnapshotAliveCollector}
 * for boolean liveness.
 */
final class SnapshotAgeCollector implements Collector
{
    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.snapshot_age', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $samples = [];
        $now = Date::now()
            ->getTimestamp();
        foreach (SnapshotPairs::all() as $pair) {
            $capturedAt = $redis->command('get', [KeyPrefix::make("live:at:{$pair['connection']}:{$pair['queue']}")]);
            // Absent key (never snapshotted, or the snapshot loop died and
            // the key aged out) omits the sample so alerts read
            // `absent(...)` rather than a misleading zero.
            if (! is_numeric($capturedAt)) {
                continue;
            }

            $age = max(0, $now - (int) $capturedAt);

            $samples[] = new Sample(
                name: 'queue_insights_snapshot_age_seconds',
                labels: ['connection' => $pair['connection'], 'queue' => $pair['queue']],
                value: (float) $age,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_snapshot_age_seconds',
            type: 'gauge',
            help: 'Seconds since the snapshot loop last wrote live:depth for this queue.',
            samples: $samples,
        )];
    }
}
