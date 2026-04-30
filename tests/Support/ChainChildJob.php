<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ChainChildJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // No-op.
    }
}
