<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use SanderMuller\QueueInsights\QueueInsights;

/**
 * Builds the per-class row set the dashboard renders in the class
 * picker / filter options. One row per known class, decorated with
 * 24h aggregate metrics from `QueueInsights::classMetrics`.
 *
 * @internal
 */
final readonly class ClassRowsBuilder
{
    public function __construct(
        private QueueInsights $svc,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function build(): array
    {
        $rows = [];

        foreach ($this->svc->jobClasses() as $class) {
            $m = $this->svc->classMetrics($class);
            $rows[] = [
                'class' => $m->class,
                'processed_24h' => $m->processed24h,
                'failed_24h' => $m->failed24h,
                'avg_ms' => $m->avgDurationMs,
                'p95_ms' => $m->p95DurationMs,
                'max_ms' => $m->maxDurationMs,
                'last_run_at' => $m->lastRunAt,
            ];
        }

        return $rows;
    }
}
