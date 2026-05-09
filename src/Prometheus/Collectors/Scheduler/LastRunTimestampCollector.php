<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\Config;

/**
 * `queue_insights_scheduled_task_last_run_timestamp{task,status}` —
 * gauge holding the unix timestamp (seconds) of the most recent run
 * per (taskKey, status). Status values: `success` / `failed`.
 *
 * Source: `sched:counters:{taskKey}` hash fields `last_success_at` /
 * `last_failed_at` (both stored as ms unix ts). Operators page on
 * "no success in N hours" via:
 *
 *     time() - queue_insights_scheduled_task_last_run_timestamp{status="success"} > N
 *
 * The `last_run_at` field exists on the same hash but the per-status
 * pair is more useful operationally; the collector ignores it.
 */
final readonly class LastRunTimestampCollector implements Collector
{
    use SchedulerEnabled;

    private const array STATUS_FIELDS = [
        'success' => 'last_success_at',
        'failed' => 'last_failed_at',
    ];

    public function __construct(
        private TaskFilter $taskFilter,
        private CountersReader $counters,
    ) {}

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_last_run_timestamp', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $samples = [];

        foreach ($this->taskFilter->tasks() as $task) {
            foreach (self::STATUS_FIELDS as $status => $field) {
                $value = $this->counters->field($task, $field);
                if (! is_numeric($value)) {
                    continue;
                }

                $samples[] = new Sample(
                    name: 'queue_insights_scheduled_task_last_run_timestamp',
                    labels: ['task' => $task, 'status' => $status],
                    value: ((float) $value) / 1000.0,
                );
            }
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_task_last_run_timestamp',
            type: 'gauge',
            help: 'Unix timestamp (seconds) of the last success/failed run per task. Sample omitted when no run of that status has been recorded.',
            samples: $samples,
        )];
    }
}
