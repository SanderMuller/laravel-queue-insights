<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;

/**
 * Collects state for one or more metric families. Returning a list (not a
 * single {@see MetricFamily}) lets one collector emit the count + sum +
 * max trio for the duration aggregate without three separate hash reads.
 *
 * @internal
 */
interface Collector
{
    public function isEnabled(): bool;

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array;
}
