<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

/** @internal */
enum RetryStatus
{
    case Ok;
    case RateLimited;
    case NonZeroExit;
    case Threw;
}
