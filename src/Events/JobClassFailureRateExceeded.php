<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class JobClassFailureRateExceeded
{
    public function __construct(
        public string $jobClass,
        public int $failed,
        public int $processed,
        public int $total,
        public float $ratio,
        public float $ratioThreshold,
        public int $minJobs,
        public string $bucket,
        public string $severity,
    ) {}
}
