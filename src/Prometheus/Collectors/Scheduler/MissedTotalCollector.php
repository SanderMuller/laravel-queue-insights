<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

/**
 * `queue_insights_scheduled_task_missed_total{task}` — monotonic counter of missed-run
 * detections. Source: `sched:counters:{taskKey}` field `total_missed`,
 * incremented by `RunStore::recordMissed` (called from `MissedRunReconciler`).
 */
final readonly class MissedTotalCollector extends PerTaskCounterCollector
{
    protected function hashField(): string
    {
        return 'total_missed';
    }

    protected function metricName(): string
    {
        return 'queue_insights_scheduled_task_missed_total';
    }

    protected function helpText(): string
    {
        return 'Total missed-run detections per task. Missed = cron-expression next-fire passed without a Starting event inside drift_seconds.';
    }

    protected function metricToggleKey(): string
    {
        return 'prometheus.metrics.scheduler_missed_total';
    }
}
