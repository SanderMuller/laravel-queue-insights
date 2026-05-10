<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

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

        return ['connection' => $connection, 'queue' => $queue];
    }
}
