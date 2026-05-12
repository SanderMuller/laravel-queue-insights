<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

final class CanonicalQueueKey
{
    public static function from(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Queue input is empty.');
        }

        if (preg_match('/^https?:\/\//i', $input) === 1) {
            $lastSlash = strrpos($input, '/');
            $candidate = $lastSlash === false ? $input : substr($input, $lastSlash + 1);
        } else {
            $candidate = $input;
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $candidate) ?? '';

        if ($normalized === '') {
            throw new InvalidArgumentException("Queue input [{$input}] normalizes to an empty key.");
        }

        return $normalized;
    }

    /**
     * Canonicalise the queue value, falling back to the connection's
     * configured default queue (`queue.connections.{$connection}.queue`)
     * when `$input` is empty.
     *
     * Empty `$input` is the JobQueued shape Laravel emits when a job is
     * dispatched without an explicit `->onQueue()` — the driver routes
     * to its `$this->default` at push time, but the event still carries
     * `null` / empty for `$event->queue`. Without this lookup the listener
     * would write to `pending-zset:{conn}:default` while the worker (which
     * reads the real queue off the popped job) writes to
     * `pending-zset:{conn}:{configured-default}` — keys diverge and the
     * pending entry never clears, tripping oldest_pending alerts on
     * long-completed jobs (Vapor / SQS hit this when `SQS_QUEUE` is set
     * to anything other than the literal string 'default').
     *
     * Last-resort fallback is the literal `'default'` for environments
     * where the connection isn't configured or `$connection` is empty.
     */
    public static function fromOrDefault(string $input, string $connection): string
    {
        $trimmed = trim($input);
        if ($trimmed !== '') {
            return self::from($trimmed);
        }

        $configured = $connection !== ''
            ? config("queue.connections.{$connection}.queue")
            : null;

        $resolved = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'default';

        return self::from($resolved);
    }
}
