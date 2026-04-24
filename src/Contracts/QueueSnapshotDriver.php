<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Contracts;

interface QueueSnapshotDriver
{
    public function depth(string $queue): int;

    public function inFlight(string $queue): ?int;

    public function delayed(string $queue): ?int;

    public function canonicalKey(string $queue): string;
}
