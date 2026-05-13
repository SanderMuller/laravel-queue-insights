<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Resolve a Laravel queue connection name to its operator-declared canonical
 * alias. Returns the input unchanged when no mapping exists. Stateless +
 * Octane-safe; one hash lookup over an operator-supplied map per call.
 *
 * Validator enforces single-hop resolution at boot — no transitive chains,
 * no mutual cycles — so this helper does not need to walk the graph.
 *
 * @see ConfigValidator::validateConnectionAliases
 */
final class ConnectionAlias
{
    public static function canonical(string $connection): string
    {
        if ($connection === '') {
            return $connection;
        }

        $map = Config::array('connection_aliases');
        $resolved = $map[$connection] ?? null;

        return is_string($resolved) && $resolved !== '' ? $resolved : $connection;
    }
}
