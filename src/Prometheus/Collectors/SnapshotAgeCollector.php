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
 * Age of the most recent snapshot per queue — derived from the
 * `live:depth:{c}:{q}` TTL (the key is written with a 90 s SETEX).
 *
 * The metric is **omitted** when the key is absent so a dead snapshot
 * loop reads as `absent(queue_insights_snapshot_age_seconds)`, not as
 * "0 seconds — looks fresh". Pair with {@see SnapshotAliveCollector}
 * for boolean liveness.
 */
final class SnapshotAgeCollector implements Collector
{
    private const int LIVE_DEPTH_TTL_SECONDS = 90;

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
        foreach (SnapshotPairs::all() as $pair) {
            $ttl = $redis->command('ttl', [KeyPrefix::make("live:depth:{$pair['connection']}:{$pair['queue']}")]);
            // -2 (key missing) and -1 (no TTL) both omit the sample so a
            // dead snapshot loop reads as `absent(...)` rather than zero.
            if (! is_int($ttl)) {
                continue;
            }

            if ($ttl < 0) {
                continue;
            }

            $age = max(0, self::LIVE_DEPTH_TTL_SECONDS - $ttl);

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
