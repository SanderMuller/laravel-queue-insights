<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\Config;

/**
 * `queue_insights_scheduled_task_runs_total{task,status}` — monotonic
 * counter per (taskKey, status). Status values: `success` / `failed` /
 * `skipped`.
 *
 * Source: `sched:counters:{taskKey}` hash fields, read through the
 * shared `CountersReader`. `success` is derived as `total_runs -
 * total_failed`; hung + missed are NOT folded in (they live in their
 * own families).
 */
final readonly class RunsTotalCollector implements Collector
{
    use SchedulerEnabled;

    public function __construct(
        private TaskFilter $taskFilter,
        private CountersReader $counters,
    ) {}

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_runs_total', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $samples = [];

        foreach ($this->taskFilter->tasks() as $task) {
            $totalRuns = $this->intField($task, 'total_runs');
            $totalFailed = $this->intField($task, 'total_failed');
            $totalSkipped = $this->intField($task, 'total_skipped');
            $totalSuccess = max(0, $totalRuns - $totalFailed);

            foreach ([
                'success' => $totalSuccess,
                'failed' => $totalFailed,
                'skipped' => $totalSkipped,
            ] as $status => $value) {
                $samples[] = new Sample(
                    name: 'queue_insights_scheduled_task_runs_total',
                    labels: ['task' => $task, 'status' => $status],
                    value: (float) $value,
                );
            }
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_task_runs_total',
            type: 'counter',
            help: 'Total scheduled-task runs per (task, status). Status: success | failed | skipped. Hung/missed are separate families.',
            samples: $samples,
        )];
    }

    private function intField(string $task, string $field): int
    {
        $value = $this->counters->field($task, $field);

        return is_numeric($value) ? (int) $value : 0;
    }
}
