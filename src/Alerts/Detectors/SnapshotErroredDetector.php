<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\AlertText;
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
        if (! $this->ruleEnabled()) {
            return null;
        }

        $key = KeyPrefix::make("snapshot:error:{$connection}:{$canonicalQueue}");
        $message = $this->redis()->command('get', [$key]);

        return $this->evaluate($connection, $canonicalQueue, $message);
    }

    /**
     * Build the Issue from a preloaded `snapshot:error:{c}:{q}` GET. Callers
     * MUST gate on `ruleEnabled()` before enqueuing the read.
     */
    public function evaluate(string $connection, string $canonicalQueue, mixed $messageRaw): ?Issue
    {
        if (! is_string($messageRaw)) {
            return null;
        }

        // Exception messages routinely carry newlines + framework context.
        // `AlertText::sanitise` keeps the alert single-line + bounded so a
        // verbose stack-trace fragment can't split log records or push
        // past mail-subject / Slack-title limits. The raw message is still
        // on the `snapshot:error:{c}:{q}` Redis key for log forwarding.
        $message = AlertText::sanitise($messageRaw);
        if ($message === '') {
            $message = '(no message)';
        }

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

    public function ruleEnabled(): bool
    {
        return Config::bool('alerts.rules.snapshot_errored.enabled', true);
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
