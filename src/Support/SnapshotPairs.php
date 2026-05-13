<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

/**
 * Iterates `queue-insights.snapshots` and yields the (connection,
 * canonicalQueue) pairs every queue-scoped collector reads against.
 * Single source of truth so collectors can't drift on snapshot-entry
 * shape parsing or canonicalisation.
 *
 * @internal
 */
final class SnapshotPairs
{
    /**
     * @return list<array{connection: string, queue: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if ($connection === '') {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            if ($queue === '') {
                continue;
            }

            try {
                $canonical = CanonicalQueueKey::from($queue);
            } catch (InvalidArgumentException) {
                continue;
            }

            // Canonicalise connection so Prometheus collectors emit a
            // consistent `connection` label that matches the listener-side
            // writes once `connection_aliases` is published. Producers /
            // workers / dashboard / Prometheus all converge on the same name.
            $out[] = ['connection' => ConnectionAlias::canonical($connection), 'queue' => $canonical];
        }

        return $out;
    }
}
