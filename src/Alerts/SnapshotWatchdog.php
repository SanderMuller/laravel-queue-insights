<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * Dashboard-only watchdog (spec §1.1). The `live:depth:{c}:{q}` keys all
 * have a 90s TTL — if NONE are present for any configured queue, the
 * snapshot command has been dead for at least 90s. Renders a top-level
 * red banner so operators don't stare at a frozen dashboard.
 *
 * Cannot run from inside `QueueInsightsSnapshotCommand` — `writeMetric`
 * stamps the keys before any in-loop detector would read them. The
 * dashboard is the only correct fire-site.
 */
final class SnapshotWatchdog
{
    /**
     * Returns true when no `live:depth:{c}:{q}` key exists for any
     * configured snapshot pair. Empty config → returns false (nothing
     * to watch).
     */
    public function isSnapshotCommandDead(): bool
    {
        $pairs = $this->configuredPairs();
        if ($pairs === []) {
            return false;
        }

        $redis = $this->redis();

        foreach ($pairs as [$connection, $canonicalQueue]) {
            try {
                $exists = $redis->command('exists', [
                    KeyPrefix::make("live:depth:{$connection}:{$canonicalQueue}"),
                ]);
            } catch (Throwable) {
                // If Redis itself is unreachable, treat as alive to avoid a
                // misleading watchdog banner driven by a separate fault.
                return false;
            }

            if (is_numeric($exists) && (int) $exists > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function configuredPairs(): array
    {
        $pairs = [];
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

            try {
                $pairs[] = [$connection, CanonicalQueueKey::from($queue)];
            } catch (Throwable) {
                continue;
            }
        }

        return $pairs;
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
