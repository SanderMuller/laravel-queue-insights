<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class JobClassP95Exceeded
{
    public function __construct(
        public string $jobClass,
        public int $p95Ms,
        public int $thresholdMs,
        public int $sampleCount,
        public string $severity,
    ) {}
}
