<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\Config;

/**
 * `queue_insights_scheduled_task_runtime_sum_seconds_total{task}` —
 * monotonic counter accumulating runtime (seconds) across every
 * successful or failed run. Pairs with
 * `queue_insights_scheduled_task_runs_total` for mean runtime via
 * `rate(sum) / rate(runs_total{status=~"success|failed"})`.
 *
 * Source: `sched:counters:{taskKey}` hash field `runtime_sum_ms`,
 * HINCRBY-maintained on every `RunStore::recordFinish` call.
 *
 * `_max_seconds` deferred from v1: would need a Lua HSET-IF-GREATER
 * write path; revisit when adopters ask.
 */
final readonly class RuntimeSumCollector implements Collector
{
    use SchedulerEnabled;

    public function __construct(
        private TaskFilter $taskFilter,
        private CountersReader $counters,
    ) {}

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool('prometheus.metrics.scheduler_runtime_sum', false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $samples = [];

        foreach ($this->taskFilter->tasks() as $task) {
            $value = $this->counters->field($task, 'runtime_sum_ms');

            // Omit the sample when the field is absent (task registered
            // but no runs finished yet) so the counter doesn't appear
            // before its first real value.
            if (! is_numeric($value)) {
                continue;
            }

            $samples[] = new Sample(
                name: 'queue_insights_scheduled_task_runtime_sum_seconds_total',
                labels: ['task' => $task],
                value: ((float) $value) / 1000.0,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_scheduled_task_runtime_sum_seconds_total',
            type: 'counter',
            help: 'Lifetime sum of scheduled-task runtimes in seconds. Pair with queue_insights_scheduled_task_runs_total for mean.',
            samples: $samples,
        )];
    }
}
