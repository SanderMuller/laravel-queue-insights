<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

/** @internal */
final readonly class RetryActor
{
    public function __construct(
        public int|string|null $userId,
        public string $rateLimitKey,
    ) {}
}
