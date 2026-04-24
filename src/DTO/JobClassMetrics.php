<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\DTO;

use Carbon\CarbonInterface;

final readonly class JobClassMetrics
{
    public function __construct(
        public string $class,
        public int $processed24h,
        public int $failed24h,
        public ?float $avgDurationMs,
        public ?int $maxDurationMs,
        public ?int $p95DurationMs,
        public ?CarbonInterface $lastRunAt,
    ) {}
}
