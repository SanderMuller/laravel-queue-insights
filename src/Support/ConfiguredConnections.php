<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Reads the distinct connection names from `queue-insights.snapshots` config.
 * Single source of truth for the route's `whereIn` constraint, the dashboard
 * mount's allowed-connection check, the connection-nav builder, the snapshot
 * command's per-connection prune, and any other caller that needs the
 * deduped list.
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
        $snapshots = array_values(array_filter(Config::array('snapshots'), is_array(...)));

        $names = array_filter(
            array_column($snapshots, 'connection'),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        );

        return array_values(array_unique($names));
    }
}
