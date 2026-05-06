<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

/**
 * `queue_insights_jobs_processed_total` — monotonic counter from the
 * `processed-total:{class}:{connection}` INCR keys written by
 * `RecordJobProcessed::writeProcessedMonotonic`.
 */
final readonly class JobsProcessedCollector extends PerClassMonotonicCounterCollector
{
    protected function keyShape(): string
    {
        return 'processed-total';
    }

    protected function metricName(): string
    {
        return 'queue_insights_jobs_processed_total';
    }

    protected function helpText(): string
    {
        return 'Total processed jobs per (class, connection). Monotonic INCR — no retention rollover.';
    }

    protected function metricToggleKey(): string
    {
        return 'prometheus.metrics.jobs_processed_total';
    }
}
