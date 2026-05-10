<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

/** @internal */
final readonly class RetryOutcome
{
    public function __construct(
        public RetryStatus $status,
        public string $message,
    ) {}
}
