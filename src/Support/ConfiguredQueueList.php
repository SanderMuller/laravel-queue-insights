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

        if (self::shouldAutodiscover()) {
            foreach (HorizonQueueDiscovery::discover() as $pair) {
                self::push($out, $seen, $pair['connection'], $pair['queue'], $scopeConnection);
            }
        }

        return $out;
    }

    /**
     * Tri-state `horizon.autodiscover`:
     *   false   — never autodiscover
     *   true    — autodiscover only when Horizon's provider is loaded (default)
     *   'force' — autodiscover from config regardless of provider state
     *
     * Read raw via `config()`: `Config::bool` would coerce `'force'` to its
     * default arg and silently misbehave. `ConfigValidator::validateHorizon`
     * has already rejected anything outside `bool|'force'` at boot, so the
     * strict `=== true` is safe — a runtime `config()->set` that bypasses
     * validation falls through to "off", which is the conservative choice.
     */
    private static function shouldAutodiscover(): bool
    {
        $setting = config('queue-insights.horizon.autodiscover', true);

        if ($setting === 'force') {
            return true;
        }

        return $setting === true && HorizonQueueDiscovery::isActive();
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
