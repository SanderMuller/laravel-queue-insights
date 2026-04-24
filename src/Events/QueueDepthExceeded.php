<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

final readonly class QueueDepthExceeded
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $depth,
        public int $threshold,
    ) {}
}
