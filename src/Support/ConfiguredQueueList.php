<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

/**
 * Builds the `{connection, queue}` list driving the dashboard's Queues
 * panel + pending/in-flight aggregation. Unions static `snapshots[]` with
 * Horizon's `configuredQueues()`-equivalent supervisor list, deduped on
 * `{connection}|{canonical-queue}`. Static entries win on collision
 * (inserted first); the snapshots tuple's raw queue string is preserved
 * (canonical form is only used for the seen-set).
 */
final class ConfiguredQueueList
{
    /**
     * @return list<array{connection: string, queue: string}>
     */
    public static function build(?string $scopeConnection = null): array
    {
        $out = [];
        $seen = [];

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            if ($queue === '') {
                continue;
            }

            self::push($out, $seen, $connection, $queue, $scopeConnection);
        }

        if (Config::bool('horizon.autodiscover', true)) {
            foreach (HorizonQueueDiscovery::discover() as $pair) {
                self::push($out, $seen, $pair['connection'], $pair['queue'], $scopeConnection);
            }
        }

        return $out;
    }

    /**
     * @param  list<array{connection: string, queue: string}>  $out
     * @param  array<string, true>  $seen
     */
    private static function push(array &$out, array &$seen, string $connection, string $queue, ?string $scopeConnection): void
    {
        // Canonicalise BEFORE scope filter so a scope value like
        // "redis-staging" matches a snapshot entry written as "redis" when
        // aliases collapse them.
        $canonicalConn = ConnectionAlias::canonical($connection);
        $canonicalScope = $scopeConnection === null
            ? null
            : ConnectionAlias::canonical($scopeConnection);
        if ($canonicalScope !== null && $canonicalConn !== $canonicalScope) {
            return;
        }

        try {
            $canonicalQueue = CanonicalQueueKey::from($queue);
        } catch (InvalidArgumentException) {
            return;
        }

        $slot = $canonicalConn . '|' . $canonicalQueue;
        if (isset($seen[$slot])) {
            return;
        }

        $seen[$slot] = true;
        $out[] = ['connection' => $canonicalConn, 'queue' => $queue];
    }
}
