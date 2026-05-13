<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Reads the distinct (canonical) connection names visible to the package —
 * unioned from static `queue-insights.snapshots` and Horizon autodiscovery.
 * Single source of truth for the route's `whereIn` constraint, the dashboard
 * mount's allowed-connection check, the connection-nav builder, the snapshot
 * command's per-connection prune, the unscoped-dashboard per-connection
 * gate sweep, and any per-connection Prometheus collector.
 *
 * @internal
 */
final class ConfiguredConnections
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        // Source from `ConfiguredQueueList::build` rather than raw `snapshots[]`
        // so Horizon-autodiscovered connections appear in:
        //  - the route's whereIn constraint + dashboard mount guard (404 fix)
        //  - the connection-nav tabs (visibility fix)
        //  - the unscoped dashboard's per-connection `viewQueueInsightsConnection`
        //    gate check (otherwise per-connection auth is bypassed for
        //    Horizon-only connections — security)
        //  - per-connection Prometheus class collectors
        //
        // `ConfiguredQueueList::build` already canonicalises connection names
        // through `ConnectionAlias`, so the dedup-by-name here is by canonical
        // value.
        $names = array_unique(array_column(ConfiguredQueueList::build(), 'connection'));

        return array_values($names);
    }
}
