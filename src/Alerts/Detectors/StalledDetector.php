<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Fires when no recent worker pickups have happened (`wait:{c}:{q}` zset)
 * AND the queue still has depth. Reads the **starts** zset, not a
 * completions zset (none exists per-queue) — `wait:{c}:{q}` is populated by
 * RecordJobProcessing on every successful pickup, so absence of recent
 * entries means workers aren't picking jobs up.
 */
final class StalledDetector
{
    public const string RULE = 'stalled';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! Config::bool('alerts.rules.stalled.enabled', true)) {
            return null;
        }

        $idleSeconds = Config::int('alerts.rules.stalled.idle_seconds', 120);
        $minDepth = Config::int('alerts.rules.stalled.min_depth', 1);
        $severity = $this->severity();

        $redis = $this->redis();

        $depthRaw = $redis->command('get', [
            KeyPrefix::make("live:depth:{$connection}:{$canonicalQueue}"),
        ]);

        // No live:depth key = snapshot command itself isn't running; let the
        // dashboard-only snapshot_command_dead watchdog cover that case so we
        // don't fire two alerts for the same root cause.
        if (! is_string($depthRaw) && ! is_numeric($depthRaw)) {
            return null;
        }

        $depth = (int) $depthRaw;
        if ($depth < $minDepth) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $threshold = $now - $idleSeconds;

        $recent = $redis->command('zcount', [
            KeyPrefix::make("wait:{$connection}:{$canonicalQueue}"),
            (string) $threshold,
            '+inf',
        ]);

        if (is_int($recent) && $recent > 0) {
            return null;
        }

        return new Issue(
            rule: self::RULE,
            severity: $severity,
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Queue stalled',
            description: "No worker pickups on {$connection}:{$canonicalQueue} in {$idleSeconds}s while depth is {$depth}.",
            context: [
                'depth' => $depth,
                'idle_seconds' => $idleSeconds,
                'min_depth' => $minDepth,
            ],
            detectedAt: $now,
        );
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.stalled.severity',
            AlertSeverity::class,
            AlertSeverity::Critical,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
