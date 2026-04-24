<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;

final class NullSnapshotDriver implements QueueSnapshotDriver
{
    public function depth(string $queue): int
    {
        return 0;
    }

    public function inFlight(string $queue): ?int
    {
        return null;
    }

    public function delayed(string $queue): ?int
    {
        return null;
    }

    public function canonicalKey(string $queue): string
    {
        return CanonicalQueueKey::from($queue);
    }
}
