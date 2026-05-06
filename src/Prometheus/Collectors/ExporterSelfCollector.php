<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;

/**
 * `queue_insights_exporter_collect_duration_seconds` — single-sample
 * gauge holding the wall-clock duration of the previous collect cycle.
 * Not a histogram: the exporter cannot maintain monotonic bucket state
 * without yet another write path (the very thing §4.1 rejected for
 * job durations).
 *
 * The Registry calls {@see record} after every collect cycle and the
 * collector is invoked LAST in the registry's collector list so the
 * sample reflects the prior in-cycle work.
 */
final class ExporterSelfCollector implements Collector
{
    private float $lastDurationSeconds = 0.0;

    public function record(float $seconds): void
    {
        $this->lastDurationSeconds = max(0.0, $seconds);
    }

    public function isEnabled(): bool
    {
        // Always emitted — the catalogue lists it under a master toggle
        // but operational value is highest when scrapes can compare
        // exporter cost against own-process metrics. No separate
        // `prometheus.metrics.exporter_self` config knob in v1.
        return true;
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        return [new MetricFamily(
            name: 'queue_insights_exporter_collect_duration_seconds',
            type: 'gauge',
            help: 'Wall-clock seconds the previous Prometheus collect cycle took.',
            samples: [
                new Sample(
                    name: 'queue_insights_exporter_collect_duration_seconds',
                    labels: [],
                    value: $this->lastDurationSeconds,
                ),
            ],
        )];
    }
}
