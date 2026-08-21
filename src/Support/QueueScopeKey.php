<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

/**
 * Decompose the dashboard's `selectedQueue` URL prop (canonical
 * `'{connection}:{queue}'` shape; written by `QueueInsightsDashboard::
 * selectQueue`) into its connection + queue parts. Centralises the
 * `str_contains(':') + explode(':', …, 2)` repetition that otherwise
 * appears at every read site.
 *
 * Returns null when the input is empty or malformed (no colon, empty
 * connection, empty queue) so callers can early-out without their own
 * guards.
 *
 * @internal
 */
final class QueueScopeKey
{
    /**
     * @return array{connection: string, queue: string}|null
     */
    public static function decompose(string $key): ?array
    {
        if ($key === '' || ! str_contains($key, ':')) {
            return null;
        }

        [$connection, $queue] = explode(':', $key, 2);

        if ($connection === '' || $queue === '') {
            return null;
        }

        // Canonicalise both segments so legacy bookmarks resolve to the
        // canonical scope. Connection: alias map collapses producer/worker
        // names. Queue: CanonicalQueueKey strips driver-specific shapes
        // (e.g. SQS URLs `https://sqs.../work` → `work`) so a hand-written
        // `?qk=sqs:https://…/work` matches dashboard rows keyed by `work`, and
        // the connection's queue-name suffix is stripped with it.
        $canonicalConnection = ConnectionAlias::canonical($connection);

        try {
            $canonicalQueue = CanonicalQueueKey::forConnection($queue, $canonicalConnection);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'connection' => $canonicalConnection,
            'queue' => $canonicalQueue,
        ];
    }

    /**
     * Compose the canonical `'{connection}:{queue}'` URL prop. Centralises
     * the raw concatenation that previously sat inline at
     * `QueueInsightsDashboard::selectQueue` so the connection segment is
     * canonicalised at write time too.
     */
    public static function compose(string $connection, string $queue): string
    {
        return ConnectionAlias::canonical($connection) . ':' . $queue;
    }
}
