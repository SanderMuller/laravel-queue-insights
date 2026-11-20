<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Wraps an exception a queue-insights listener swallowed so the host's
 * exception handler (Sentry, Bugsnag, …) sees it. The listener name is in
 * the message so error trackers group one issue per listener.
 */
final class ListenerFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $listener,
        Throwable $previous,
    ) {
        parent::__construct(
            "queue-insights: {$listener} failed: {$previous->getMessage()}",
            0,
            $previous,
        );
    }
}
