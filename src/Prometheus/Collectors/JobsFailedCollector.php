<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

/**
 * `queue_insights_jobs_failed_total` — monotonic counter from the
 * `failed-total:{class}:{connection}` INCR keys written by
 * `RecordJobFailed::writeFailedMonotonic`.
 */
final readonly class JobsFailedCollector extends PerClassMonotonicCounterCollector
{
    protected function keyShape(): string
    {
        return 'failed-total';
    }

    protected function metricName(): string
    {
        return 'queue_insights_jobs_failed_total';
    }

    protected function helpText(): string
    {
        return 'Total failed jobs per (class, connection). Monotonic INCR — no retention rollover.';
    }

    protected function metricToggleKey(): string
    {
        return 'prometheus.metrics.jobs_failed_total';
    }
}
