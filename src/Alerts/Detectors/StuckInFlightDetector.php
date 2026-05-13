<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ZsetHead;

/**
 * Fires when the longest-running in-flight job has been executing past the
 * configured threshold. `inflight-zset` score = startedAt (set by
 * RecordJobProcessing::markInFlight). Requires `pending.enabled = true`.
 */
final class StuckInFlightDetector
{
    public const string RULE = 'stuck_inflight';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! $this->ruleEnabled()) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $redis = $this->redis();

        $row = $redis->command('zrange', [
            KeyPrefix::make("inflight-zset:{$connection}:{$canonicalQueue}"),
            0,
            0,
            ['WITHSCORES' => true],
        ]);

        $head = ZsetHead::firstMemberScore($row);

        // Pre-flight the age check before issuing the per-uuid HGET — keeps
        // the snapshot-command per-queue path free of an extra round-trip
        // for in-flight rows still within the configured window. evaluate()
        // re-checks the threshold so this stays an exact filter.
        $jobClass = null;
        if ($head !== null && $now - (int) $head[1] >= $this->thresholdSeconds()) {
            $jobClass = $this->resolveClass($redis, $head[0]);
        }

        return $this->evaluate($connection, $canonicalQueue, $head, $jobClass, $now);
    }

    /**
     * Build the Issue from a preloaded zset head + (optional) job class.
     * Callers MUST gate on `ruleEnabled()` before enqueuing reads.
     *
     * @param  array{0: string, 1: float|int}|null  $head  [uuid, startedAt] or null when absent
     */
    public function evaluate(string $connection, string $canonicalQueue, ?array $head, ?string $jobClass, int $now): ?Issue
    {
        if ($head === null) {
            return null;
        }

        $thresholdSeconds = Config::int('alerts.rules.stuck_inflight.seconds', 300);
        [$uuid, $startedAtFloat] = $head;
        $startedAt = (int) $startedAtFloat;
        $age = $now - $startedAt;
        if ($age < $thresholdSeconds) {
            return null;
        }

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Stuck in-flight job',
            description: "Oldest in-flight job on {$connection}:{$canonicalQueue} has been running {$age}s.",
            context: [
                'age_seconds' => $age,
                'threshold_seconds' => $thresholdSeconds,
                'oldest_uuid' => $uuid,
                'oldest_class' => $jobClass ?? '',
                'started_at' => $startedAt,
            ],
            detectedAt: $now,
        );
    }

    public function ruleEnabled(): bool
    {
        return Config::bool('alerts.rules.stuck_inflight.enabled', true)
            && Config::bool('pending.enabled', true);
    }

    public function thresholdSeconds(): int
    {
        return Config::int('alerts.rules.stuck_inflight.seconds', 300);
    }

    private function resolveClass(RedisConnection $redis, string $uuid): ?string
    {
        $value = $redis->command('hget', [KeyPrefix::make("pending:{$uuid}"), 'class']);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.stuck_inflight.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
