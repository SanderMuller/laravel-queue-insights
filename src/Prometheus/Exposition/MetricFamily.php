<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Exposition;

/**
 * One Prometheus metric family — a name + TYPE + HELP plus all samples
 * sharing that family. Emitted as a `# HELP` / `# TYPE` banner pair
 * followed by the samples, in family-then-sample order.
 *
 * @internal
 */
final readonly class MetricFamily
{
    /**
     * @param  list<Sample>  $samples
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $help,
        public array $samples = [],
    ) {}
}
