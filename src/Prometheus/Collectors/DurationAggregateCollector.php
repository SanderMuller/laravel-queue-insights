<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\ClassFilter;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * `queue_insights_job_duration_count_total` (counter) +
 * `queue_insights_job_duration_sum_seconds_total` (counter) +
 * `queue_insights_job_duration_max_seconds` (gauge), from one
 * `HMGET duration:{class}:{connection} count sum_ms max_ms` per
 * (class, connection). Spec
 * `internal/specs/prometheus-export.md` §4.1 — the capped LIST
 * histogram is rejected as non-monotonic; the aggregate hash is
 * already maintained on every processed job.
 *
 * Mean = `rate(sum) / rate(count)` Prometheus-side. Percentiles are
 * out-of-scope for v1 (HINCRBY aggregate carries no quantile state).
 */
final readonly class DurationAggregateCollector implements Collector
{
    public function __construct(
        private ClassFilter $classFilter,
    ) {}

    public function isEnabled(): bool
    {
        return Config::bool('prometheus.metrics.job_duration', true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $countSamples = [];
        $sumSamples = [];
        $maxSamples = [];

        foreach (ConfiguredConnections::all() as $connection) {
            foreach ($this->classFilter->classesFor($connection) as $class) {
                $key = KeyPrefix::classKey('duration', $class, $connection);
                $values = $redis->command('hmget', [$key, 'count', 'sum_ms', 'max_ms']);
                if (! is_array($values)) {
                    continue;
                }

                $count = $values[0] ?? null;
                $sumMs = $values[1] ?? null;
                $maxMs = $values[2] ?? null;

                $labels = ['class' => $class, 'connection' => $connection];

                if (is_numeric($count)) {
                    $countSamples[] = new Sample(
                        name: 'queue_insights_job_duration_count_total',
                        labels: $labels,
                        value: (float) $count,
                    );
                }

                if (is_numeric($sumMs)) {
                    $sumSamples[] = new Sample(
                        name: 'queue_insights_job_duration_sum_seconds_total',
                        labels: $labels,
                        value: ((float) $sumMs) / 1000.0,
                    );
                }

                if (is_numeric($maxMs)) {
                    $maxSamples[] = new Sample(
                        name: 'queue_insights_job_duration_max_seconds',
                        labels: $labels,
                        value: ((float) $maxMs) / 1000.0,
                    );
                }
            }
        }

        return [
            new MetricFamily(
                name: 'queue_insights_job_duration_count_total',
                type: 'counter',
                help: 'Processed-job count per (class, connection) — HINCRBY-backed, monotonic.',
                samples: $countSamples,
            ),
            new MetricFamily(
                name: 'queue_insights_job_duration_sum_seconds_total',
                type: 'counter',
                help: 'Sum of processed-job durations per (class, connection) in seconds. Pair with _count_total for mean.',
                samples: $sumSamples,
            ),
            new MetricFamily(
                name: 'queue_insights_job_duration_max_seconds',
                type: 'gauge',
                help: 'Lifetime max processed-job duration per (class, connection) in seconds. Use max_over_time() for windowed maxima.',
                samples: $maxSamples,
            ),
        ];
    }
}
