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
 * `queue_insights_scheduled_sweeper_age_seconds` — gauge, no labels. Seconds since
 * `MissedRunReconciler` last completed a sweep tick (`sched:sweeper:last_swept_ms`,
 * plain string ms unix ts via SETEX).
 *
 * Sweeper liveness — a stalled sweeper means missed/hung detection
 * isn't running. Operators alert on this exceeding 2× `sweep_seconds`.
 *
 * Sample **omitted** when the key is absent so a never-run sweeper
 * reads as `absent(...)` rather than "0 seconds — looks fresh". Same
 * rule as the queue-side `SnapshotAgeCollector`.
 */
final class SweeperAgeCollector implements Collector
{
    use SchedulerEnabled;

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_sweeper_age', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $value = $redis->command('get', [KeyPrefix::make('sched:sweeper:last_swept_ms')]);

        $samples = [];
        if (is_numeric($value)) {
            $ageSeconds = max(0.0, (Date::now()->getTimestampMs() - (float) $value) / 1000.0);
            $samples[] = new Sample(
                name: 'queue_insights_scheduled_sweeper_age_seconds',
                labels: [],
                value: $ageSeconds,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_sweeper_age_seconds',
            type: 'gauge',
            help: 'Seconds since the schedule sweeper last completed a tick. Sample omitted when no sweep has been recorded.',
            samples: $samples,
        )];
    }
}
