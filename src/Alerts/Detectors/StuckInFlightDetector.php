<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

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
        if (! Config::bool('alerts.rules.stuck_inflight.enabled', true)) {
            return null;
        }

        if (! Config::bool('pending.enabled', true)) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $thresholdSeconds = Config::int('alerts.rules.stuck_inflight.seconds', 300);

        $redis = $this->redis();

        $row = $redis->command('zrange', [
            KeyPrefix::make("inflight-zset:{$connection}:{$canonicalQueue}"),
            0,
            0,
            ['WITHSCORES' => true],
        ]);

        if (! is_array($row) || $row === []) {
            return null;
        }

        $uuid = null;
        $startedAt = null;
        foreach ($row as $u => $score) {
            if (is_string($u) && $u !== '' && is_numeric($score)) {
                $uuid = $u;
                $startedAt = (int) $score;
                break;
            }
        }

        if ($uuid === null || $startedAt === null) {
            return null;
        }

        $age = $now - $startedAt;
        if ($age < $thresholdSeconds) {
            return null;
        }

        $jobClass = $this->resolveClass($redis, $uuid);

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
