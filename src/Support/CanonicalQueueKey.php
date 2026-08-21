<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

final class CanonicalQueueKey
{
    public static function from(string $input): string
    {
        return self::normalize($input, self::name($input));
    }

    /**
     * Connection-aware canonicalisation: identical to {@see from()}, then the
     * connection's SQS queue-name suffix is stripped so the *logical* name is
     * keyed.
     *
     * Use this wherever the queue value comes from the runtime — a job
     * (`SqsJob::getQueue()` reports the full URL, i.e. the physical name), a
     * `failed_jobs` row, a pasted dashboard URL. The producer side never sees
     * the suffix, so keying the physical name there would split one queue's
     * state across two keys. See {@see SqsQueueName} for the full rationale.
     *
     * `$connection` may be the canonical (aliased) name; {@see SqsQueueName::
     * suffixFor()} walks the alias map back to the configured connection when
     * the direct lookup misses.
     */
    public static function forConnection(string $input, string $connection): string
    {
        $name = SqsQueueName::logical(self::name($input), SqsQueueName::suffixFor($connection));

        return self::normalize($input, $name);
    }

    /**
     * The bare queue name: a queue URL collapses to its last segment, anything
     * else is taken verbatim.
     */
    private static function name(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Queue input is empty.');
        }

        if (preg_match('/^https?:\/\//i', $input) === 1) {
            $lastSlash = strrpos($input, '/');

            return $lastSlash === false ? $input : substr($input, $lastSlash + 1);
        }

        return $input;
    }

    /**
     * `$input` is carried through only so the failure message names what the
     * caller actually passed rather than the derived candidate.
     */
    private static function normalize(string $input, string $candidate): string
    {
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
     *
     * Suffix handling rides along via {@see forConnection()} — the worker reads
     * the physical queue name off the job, the producer never sees it.
     */
    public static function fromOrDefault(string $input, string $connection): string
    {
        if (trim($input) !== '') {
            return self::forConnection($input, $connection);
        }

        $configured = $connection !== ''
            ? config("queue.connections.{$connection}.queue")
            : null;

        $resolved = is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'default';

        return self::forConnection($resolved, $connection);
    }
}
