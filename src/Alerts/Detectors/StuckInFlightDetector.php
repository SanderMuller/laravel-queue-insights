<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Carbon\CarbonInterval;
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

        // Oldest startedAt within (now - retention, +inf]. The lower bound
        // excludes orphans: an `inflight-zset` member whose `startedAt` is
        // older than the pending-retention window has lost its backing
        // `pending:{uuid}` hash to TTL — typically a worker that was
        // SIGKILLed mid-job, so `RecordJobProcessed/Failed` never ran to
        // zrem the member. Without the bound that orphan is the perpetual
        // "oldest in-flight" head, firing this alert forever and masking
        // genuinely-stuck jobs behind it.
        $row = $redis->command('zrangebyscore', [
            KeyPrefix::make("inflight-zset:{$connection}:{$canonicalQueue}"),
            (string) ($now - $this->retentionSeconds()),
            '+inf',
            ['LIMIT' => [0, 1], 'WITHSCORES' => true],
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

        $context = [
            'age_seconds' => $age,
            'threshold_seconds' => $thresholdSeconds,
            'oldest_uuid' => $uuid,
            'started_at' => $startedAt,
        ];

        if (is_string($jobClass) && $jobClass !== '') {
            $context['oldest_class'] = $jobClass;
        }

        // Human-readable runtime — raw seconds stay in context as
        // `age_seconds`; the description gets the cascaded short form.
        $running = CarbonInterval::seconds($age)->cascade()->forHumans(['short' => true, 'parts' => 2]);

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Stuck in-flight job',
            description: "Oldest in-flight job on {$connection}:{$canonicalQueue} has been running {$running}.",
            context: $context,
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

    /**
     * Pending-tracking retention window. An `inflight-zset` member older
     * than this has lost its backing `pending:{uuid}` hash to TTL — the
     * cutoff for the orphan-excluding zset lower bound.
     */
    public function retentionSeconds(): int
    {
        return Config::int('pending.ttl_seconds', 86400);
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
