<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;

/**
 * `queue_insights_alert_active` — one sample per active issue with
 * value 1. Inactive rules are NOT emitted: cardinality would otherwise
 * scale `rules × queues` and Grafana panels can recover absent series
 * via `OR on() vector(0)`.
 *
 * Class-scoped detectors (failure_rate, slow_p95) emit a `class`
 * label; queue-scoped detectors omit it.
 *
 * Reuses {@see ActiveIssuesProvider} so a Prometheus scrape and a
 * dashboard render in the same 5-second window share the same
 * detector evaluation — the cache is the cross-surface bound.
 */
final readonly class AlertActiveCollector implements Collector
{
    public function __construct(
        private ActiveIssuesProvider $issues,
    ) {}

    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.alert_active', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $samples = [];
        foreach ($this->issues->get() as $issue) {
            $samples[] = new Sample(
                name: 'queue_insights_alert_active',
                labels: $this->labelsFor($issue),
                value: 1.0,
            );
        }

        return [new MetricFamily(
            name: 'queue_insights_alert_active',
            type: 'gauge',
            help: 'Active queue-insights alerts. Value is always 1; absent series = no alert. Use OR on() vector(0) Grafana-side to render gaps as 0.',
            samples: $samples,
        )];
    }

    /**
     * @return array<string, string>
     */
    private function labelsFor(Issue $issue): array
    {
        $labels = [
            'rule' => $issue->rule,
            'connection' => $issue->connection,
            'queue' => $issue->queue,
            'severity' => $issue->severity->value,
        ];

        if ($issue->jobClass !== null) {
            $labels['class'] = $issue->jobClass;
        }

        return $labels;
    }
}
