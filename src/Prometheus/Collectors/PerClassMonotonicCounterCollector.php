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
 * Shared shape for the `*_processed_total` and `*_failed_total`
 * collectors — both iterate `(connection, class)` pairs through the
 * `ClassFilter`, MGET the per-connection monotonic counter keys in
 * 100-class chunks, and emit one Sample per non-null value.
 *
 * Subclasses only declare the four pieces that differ: which Redis
 * key shape to read, which Prometheus family name to emit, the help
 * text, and the metrics.* config toggle.
 *
 * @internal
 */
abstract readonly class PerClassMonotonicCounterCollector implements Collector
{
    public function __construct(
        protected ClassFilter $classFilter,
    ) {}

    public function isEnabled(): bool
    {
        return Config::bool($this->metricToggleKey(), true);
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $redis = Redis::connection(Config::string('redis_connection', 'default'));
        $samples = [];

        foreach (ConfiguredConnections::all() as $connection) {
            $classes = $this->classFilter->classesFor($connection);
            if ($classes === []) {
                continue;
            }

            // 100-class chunks keep the MGET round-trip count bounded
            // for hosts with very large class rosters.
            foreach (array_chunk($classes, 100) as $chunk) {
                $keys = array_map(
                    fn (string $class): string => KeyPrefix::classKey($this->keyShape(), $class, $connection),
                    $chunk,
                );

                // Wrap `$keys` in an outer array — `Connection::command`
                // splats `$parameters`, and phpredis's `mget(array)`
                // signature rejects the variadic-string form Predis
                // tolerates (matches the pattern in `QueueInsights.php`).
                $values = $redis->command('mget', [$keys]);
                if (! is_array($values)) {
                    continue;
                }

                // phpredis returns positional; Predis can return
                // associative-by-key. `array_values` normalises so the
                // positional `$values[$i]` access below is driver-safe.
                $values = array_values($values);

                foreach ($chunk as $i => $class) {
                    $raw = $values[$i] ?? null;
                    if (! is_numeric($raw)) {
                        continue;
                    }

                    $samples[] = new Sample(
                        name: $this->metricName(),
                        labels: ['class' => $class, 'connection' => $connection],
                        value: (float) $raw,
                    );
                }
            }
        }

        return [new MetricFamily(
            name: $this->metricName(),
            type: 'counter',
            help: $this->helpText(),
            samples: $samples,
        )];
    }

    /**
     * Key prefix passed to {@see KeyPrefix::classKey()} — e.g.
     * `processed-total` reads `processed-total:{class}:{connection}`.
     */
    abstract protected function keyShape(): string;

    abstract protected function metricName(): string;

    abstract protected function helpText(): string;

    /**
     * Dotted config path under `prometheus.metrics.*`.
     */
    abstract protected function metricToggleKey(): string;
}
