<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * `queue_insights_scheduled_snapshot_age_seconds` — gauge, no labels. Seconds
 * since `ScheduleSnapshotter` last rewrote the schedule snapshot
 * (`sched:snapshot:at`, plain string ms unix ts). A snapshot stuck
 * for hours means workers haven't restarted — alert paired with
 * `queue_insights_scheduled_sweeper_age_seconds` for full data-plane liveness.
 *
 * The metric is **omitted** when the key is absent so a never-booted
 * scheduler subsystem reads as `absent(...)` rather than "0 seconds —
 * looks fresh". Same rule as the queue-side `SnapshotAgeCollector`.
 */
final class SnapshotAgeCollector implements Collector
{
    use SchedulerEnabled;

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_snapshot_age', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $value = $redis->command('get', [KeyPrefix::make('sched:snapshot:at')]);

        $samples = [];
        if (is_numeric($value)) {
            $ageSeconds = max(0.0, (Date::now()->getTimestampMs() - (float) $value) / 1000.0);
            $samples[] = new Sample(
                name: 'queue_insights_scheduled_snapshot_age_seconds',
                labels: [],
                value: $ageSeconds,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_snapshot_age_seconds',
            type: 'gauge',
            help: 'Seconds since the schedule snapshot was last rewritten. Sample omitted when no snapshot has been recorded.',
            samples: $samples,
        )];
    }
}
