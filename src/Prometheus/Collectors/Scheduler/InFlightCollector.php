<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * `queue_insights_scheduled_task_in_flight{task}` — gauge with value 1 for every
 * task currently in `sched:running-index`. Operators alert on
 * `time() - max_over_time(queue_insights_scheduled_task_in_flight[N]) > expected_runtime`
 * via the snapshot age + paired hung counter, but the live signal is
 * also useful for "what's running right now?" dashboards.
 *
 * The zset member is `taskKey`, score is `expected_finish_at_ms`. We
 * ZRANGE 0 -1 once per scrape, then intersect with the configured
 * `TaskFilter` so a host's allow-list is honoured.
 */
final readonly class InFlightCollector implements Collector
{
    use SchedulerEnabled;

    public function __construct(
        private TaskFilter $taskFilter,
    ) {}

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_in_flight', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $raw = $redis->command('zrange', [KeyPrefix::make('sched:running-index'), 0, -1]);

        $running = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $running[$entry] = true;
                }
            }
        }

        $samples = [];
        foreach ($this->taskFilter->tasks() as $task) {
            if (! isset($running[$task])) {
                continue;
            }

            $samples[] = new Sample(
                name: 'queue_insights_scheduled_task_in_flight',
                labels: ['task' => $task],
                value: 1.0,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_task_in_flight',
            type: 'gauge',
            help: 'Set to 1 for every task currently mid-run (Started without Finished/Failed). Sample omitted when not running.',
            samples: $samples,
        )];
    }
}
