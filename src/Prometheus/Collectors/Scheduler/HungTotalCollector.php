<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

/**
 * `queue_insights_scheduled_task_hung_total{task}` — monotonic counter of hung-run
 * detections. Source: `sched:counters:{taskKey}` field `total_hung`,
 * incremented by `RunStore::recordHung` (called from `HungTaskReconciler`).
 */
final readonly class HungTotalCollector extends PerTaskCounterCollector
{
    protected function hashField(): string
    {
        return 'total_hung';
    }

    protected function metricName(): string
    {
        return 'queue_insights_scheduled_task_hung_total';
    }

    protected function helpText(): string
    {
        return 'Total hung-run detections per task. Hung = Started without Finished/Failed within expected_runtime + grace.';
    }

    protected function metricToggleKey(): string
    {
        return 'prometheus.metrics.scheduler_hung_total';
    }
}
