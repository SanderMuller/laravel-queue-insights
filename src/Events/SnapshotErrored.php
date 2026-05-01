<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class SnapshotErrored
{
    public function __construct(
        public string $connection,
        public string $queue,
        public string $errorMessage,
        public string $severity,
    ) {}
}
