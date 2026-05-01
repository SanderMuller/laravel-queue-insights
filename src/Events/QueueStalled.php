<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class QueueStalled
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $depth,
        public int $idleSeconds,
        public string $severity,
    ) {}
}
