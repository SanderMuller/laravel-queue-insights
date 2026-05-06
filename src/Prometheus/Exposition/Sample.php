<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Exposition;

/**
 * One Prometheus sample: name + label set + numeric value. The `name`
 * is usually the family name but can carry a suffix (`_count`, `_sum`,
 * `_bucket`) for histogram / summary families that emit multiple
 * sub-metrics under one HELP/TYPE banner.
 *
 * @internal
 */
final readonly class Sample
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $name,
        public array $labels,
        public float $value,
    ) {}
}
