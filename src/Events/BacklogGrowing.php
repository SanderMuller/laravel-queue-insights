<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class BacklogGrowing
{
    public function __construct(
        public string $connection,
        public string $queue,
        public float $slopePerMinute,
        public float $minSlopePerMinute,
        public int $samples,
        public int $latestDepth,
        public int $windowSeconds,
        public string $severity,
    ) {}
}
