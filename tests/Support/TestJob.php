<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use RuntimeException;

final class TestJob implements ShouldQueue
{
    use Queueable;

    public ?string $password = null;

    public ?string $token = null;

    public ?string $payloadData = null;

    public function __construct(
        public bool $shouldFail = false,
        public int $sleepMs = 2,
    ) {}

    public function handle(): void
    {
        if ($this->sleepMs > 0) {
            Sleep::usleep($this->sleepMs * 1000);
        }

        if ($this->shouldFail) {
            throw new RuntimeException('test job failed');
        }
    }
}
