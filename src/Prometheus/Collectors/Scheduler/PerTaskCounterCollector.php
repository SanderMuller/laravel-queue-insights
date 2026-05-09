<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Shared shape for the simple per-task counter collectors
 * (`hung_total`, `missed_total`). Each subclass declares: which hash
 * field to read, which Prometheus family name to emit, the help text,
 * and the metrics.* config toggle.
 *
 * Reads route through {@see CountersReader} so multiple counter-hash
 * collectors in one scrape share a single `HGETALL` per task.
 *
 * @internal
 */
abstract readonly class PerTaskCounterCollector implements Collector
{
    use SchedulerEnabled;

    public function __construct(
        protected TaskFilter $taskFilter,
        protected CountersReader $counters,
    ) {}

    public function isEnabled(): bool
    {
        return $this->schedulerEnabled() && Config::bool($this->metricToggleKey(), false);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $samples = [];

        foreach ($this->taskFilter->tasks() as $task) {
            $value = $this->counters->field($task, $this->hashField());

            // Emit zero when absent so operators see the task in the
            // exposition (with a 0 sample) rather than a missing series
            // — these are counters where "no events yet" is a real,
            // expressible value.
            $samples[] = new Sample(
                name: $this->metricName(),
                labels: ['task' => $task],
                value: is_numeric($value) ? (float) $value : 0.0,
            );
        }

        return [new MetricFamily(
            name: $this->metricName(),
            type: 'counter',
            help: $this->helpText(),
            samples: $samples,
        )];
    }

    abstract protected function hashField(): string;

    abstract protected function metricName(): string;

    abstract protected function helpText(): string;

    abstract protected function metricToggleKey(): string;
}
