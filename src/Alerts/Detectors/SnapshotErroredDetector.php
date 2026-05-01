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
 * Fires when the snapshot command's per-queue driver call threw on the most
 * recent tick. The error key has a 10-minute TTL written by
 * `QueueInsightsSnapshotCommand::recordError`, so this rule self-clears
 * once the next successful tick (or the TTL) lands.
 */
final class SnapshotErroredDetector
{
    public const string RULE = 'snapshot_errored';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! Config::bool('alerts.rules.snapshot_errored.enabled', true)) {
            return null;
        }

        $redis = $this->redis();
        $key = KeyPrefix::make("snapshot:error:{$connection}:{$canonicalQueue}");

        $exists = $redis->command('exists', [$key]);
        if (! is_numeric($exists) || (int) $exists === 0) {
            return null;
        }

        $message = $redis->command('get', [$key]);
        $message = is_string($message) ? $message : '';

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Snapshot driver failed',
            description: "Latest snapshot for {$connection}:{$canonicalQueue} failed: {$message}",
            context: [
                'error_message' => $message,
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.snapshot_errored.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
