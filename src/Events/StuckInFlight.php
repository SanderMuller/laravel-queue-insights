<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class StuckInFlight
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $ageSeconds,
        public int $thresholdSeconds,
        public string $oldestUuid,
        public string $oldestClass,
        public string $severity,
    ) {}
}
