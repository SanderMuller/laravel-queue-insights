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
 * Fires when the oldest currently-runnable pending job has been waiting
 * longer than the configured threshold. `available_at <= now` filter skips
 * not-yet-due delayed jobs.
 *
 * Requires `pending.enabled = true`; the per-uuid hash + zset writes happen
 * in RecordJobQueued. Auto-disabled at boot when pending tracking is off.
 */
final class OldestPendingDetector
{
    public const string RULE = 'oldest_pending';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! $this->ruleEnabled()) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $redis = $this->redis();

        // Pull the oldest available_at within (now - retention, now]. The
        // lower bound matters: a `pending-zset` member whose `available_at`
        // is older than the pending-retention window is an orphan — its
        // backing `pending:{uuid}` hash has TTL'd out but the zset member
        // lingered (a cleanup zrem missed it, common on SQS). Without the
        // lower bound, that orphan is returned as the perpetual "oldest"
        // head, firing this alert forever with an ever-growing age AND
        // masking real aging jobs queued behind it.
        $row = $redis->command('zrangebyscore', [
            KeyPrefix::make("pending-zset:{$connection}:{$canonicalQueue}"),
            (string) ($now - $this->retentionSeconds()),
            (string) $now,
            ['LIMIT' => [0, 1], 'WITHSCORES' => true],
        ]);

        $head = ZsetHead::firstMemberScore($row);

        // Only resolve the job class when the head is going to cross the
        // threshold — saves a per-tick HGET for queues whose oldest pending
        // is still within the configured window. evaluate() short-circuits
        // on the same age check so this stays an exact pre-flight.
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
     * @param  array{0: string, 1: float|int}|null  $head  [uuid, availableAt] or null when absent
     */
    public function evaluate(string $connection, string $canonicalQueue, ?array $head, ?string $jobClass, int $now): ?Issue
    {
        if ($head === null) {
            return null;
        }

        $thresholdSeconds = Config::int('alerts.rules.oldest_pending.seconds', 600);
        [$uuid, $availableAtFloat] = $head;
        $availableAt = (int) $availableAtFloat;
        $age = $now - $availableAt;
        if ($age < $thresholdSeconds) {
            return null;
        }

        $context = [
            'age_seconds' => $age,
            'threshold_seconds' => $thresholdSeconds,
            'oldest_uuid' => $uuid,
            'available_at' => $availableAt,
        ];

        // Only surface oldest_class when we resolved one — an empty value
        // renders as a bare `oldest_class:` row in mail/Slack which adds
        // noise without helping triage. The typed `OldestPendingAging`
        // event falls back to '' via `ctxString` when the key is absent,
        // so omitting it here is event-payload-safe.
        if (is_string($jobClass) && $jobClass !== '') {
            $context['oldest_class'] = $jobClass;
        }

        // Human-readable wait — `{$age}s` flat is unreadable at scale (a
        // 6-day wait reads as "518400s"). Raw seconds stay in context as
        // `age_seconds` for machine consumers; the description gets the
        // cascaded short form ("6d 17h").
        $waited = CarbonInterval::seconds($age)->cascade()->forHumans(['short' => true, 'parts' => 2]);

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Oldest pending job aging',
            description: "Oldest pending job on {$connection}:{$canonicalQueue} has been waiting {$waited}.",
            context: $context,
            detectedAt: $now,
        );
    }

    public function ruleEnabled(): bool
    {
        return Config::bool('alerts.rules.oldest_pending.enabled', true)
            && Config::bool('pending.enabled', true);
    }

    public function thresholdSeconds(): int
    {
        return Config::int('alerts.rules.oldest_pending.seconds', 600);
    }

    /**
     * Pending-tracking retention window. A `pending-zset` member older than
     * this has lost its backing `pending:{uuid}` hash to TTL — past this
     * horizon the tracking can't be trusted, so it's the cutoff for the
     * orphan-excluding zset lower bound.
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
            'alerts.rules.oldest_pending.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
