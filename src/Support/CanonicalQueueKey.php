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
     * Canonicalise the queue value; when `$input` is empty fall back to the
     * connection's configured default (`queue.connections.{$connection}.queue`)
     * before defaulting to the literal `'default'`.
     *
     * `JobQueued::$queue` is empty when the dispatcher omits `->onQueue()` —
     * drivers route to `$this->default` at push time but the event keeps the
     * blank. Without resolving the connection-default here, the producer keys
     * `pending-zset:{conn}:default` while the worker reads the real queue from
     * the popped job and keys `pending-zset:{conn}:{configured-default}`; the
     * pending entry never clears and `oldest_pending` trips. Canonical repro
     * is Vapor / SQS with `SQS_QUEUE=staging_default`.
     */
    public static function fromOrDefault(string $input, string $connection): string
    {
        if (trim($input) !== '') {
            return self::from($input);
        }

        $configured = $connection !== ''
            ? config("queue.connections.{$connection}.queue")
            : null;

        $resolved = is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'default';

        return self::from($resolved);
    }
}
