<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Support\SilencedJobs;

/**
 * Per-class failure-rate detector. Reads the **current hour bucket only**
 * (`processed:{class}:{YmdH}` + `failed:{class}:{YmdH}`). The min_jobs
 * floor stops the rule from firing on a fresh hour where the first
 * one-or-two events happen to be failures.
 *
 * Rolling-window blending was considered and rejected in spec §1 — the
 * minute-1-of-hour lag for low-volume classes is the documented trade-off.
 */
final class FailureRateDetector
{
    public const string RULE = 'failure_rate';

    public function detect(string $class): ?Issue
    {
        return $this->detectMany([$class])[$class] ?? null;
    }

    /**
     * Every class's hour bucket in one pipelined round trip.
     *
     * @param  list<string>  $classes
     * @return array<string, Issue>  keyed by class; classes that don't fire are absent
     */
    public function detectMany(array $classes): array
    {
        if (! Config::bool('alerts.rules.failure_rate.enabled', true)) {
            return [];
        }

        // Short-circuit silenced classes before the Redis read.
        $silenced = resolve(SilencedJobs::class);
        $classes = array_values(array_unique(array_filter(
            $classes,
            static fn (string $class): bool => $class !== '' && ! $silenced->isSilenced($class),
        )));
        if ($classes === []) {
            return [];
        }

        $bucket = Date::now('UTC')->format('YmdH');

        $batch = new RedisBatch($this->redis());
        foreach ($classes as $class) {
            $batch->command('mget', [[
                KeyPrefix::make("processed:{$class}:{$bucket}"),
                KeyPrefix::make("failed:{$class}:{$bucket}"),
            ]]);
        }

        $replies = $batch->execute();

        $issues = [];
        foreach ($classes as $i => $class) {
            $reply = $replies[$i] ?? null;
            $issue = $this->evaluate(
                $class,
                $bucket,
                is_array($reply) ? ($reply[0] ?? null) : null,
                is_array($reply) ? ($reply[1] ?? null) : null,
            );
            if ($issue instanceof Issue) {
                $issues[$class] = $issue;
            }
        }

        return $issues;
    }

    private function evaluate(string $class, string $bucket, mixed $processedRaw, mixed $failedRaw): ?Issue
    {
        $minJobs = Config::int('alerts.rules.failure_rate.min_jobs', 20);
        $ratioThreshold = $this->ratio();

        $processed = is_numeric($processedRaw) ? (int) $processedRaw : 0;
        $failed = is_numeric($failedRaw) ? (int) $failedRaw : 0;
        $total = $processed + $failed;

        if ($total < $minJobs) {
            return null;
        }

        $ratio = $failed / $total;
        if ($ratio < $ratioThreshold) {
            return null;
        }

        $percent = number_format($ratio * 100, 1);

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: '',
            queue: '',
            jobClass: $class,
            title: 'Job class failure rate exceeded: ' . class_basename($class),
            description: "{$class} failure rate {$percent}% over {$total} jobs this hour.",
            context: [
                'failed' => $failed,
                'processed' => $processed,
                'total' => $total,
                'ratio' => $ratio,
                'ratio_threshold' => $ratioThreshold,
                'min_jobs' => $minJobs,
                'bucket' => $bucket,
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    private function ratio(): float
    {
        $value = config('queue-insights.alerts.rules.failure_rate.ratio', 0.10);

        return is_int($value) || is_float($value) ? (float) $value : 0.10;
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.failure_rate.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
