<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\SilencedJobs;

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
     * @param  ?string  $scopeConnection  when non-null, the 24h roster is read
     *                                    from the per-connection `classes:{c}`
     *                                    zset and class metrics are scoped to
     *                                    that connection's bucket keys.
     * @return list<array<string, mixed>>
     */
    public function build(?string $scopeConnection = null): array
    {
        $rows = [];
        $silenced = resolve(SilencedJobs::class);

        foreach ($this->svc->jobClasses($scopeConnection) as $class) {
            $m = $this->svc->classMetrics($class, $scopeConnection);
            $rows[] = [
                'class' => $m->class,
                'processed_24h' => $m->processed24h,
                'failed_24h' => $m->failed24h,
                'avg_ms' => $m->avgDurationMs,
                'p95_ms' => $m->p95DurationMs,
                'max_ms' => $m->maxDurationMs,
                'last_run_at' => $m->lastRunAt,
                'silenced' => $silenced->isSilenced($m->class),
            ];
        }

        return $rows;
    }
}
