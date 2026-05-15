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
 * Per-class p95 duration detector. Reads `duration:samples:{class}` (capped
 * at ~500 samples by RecordJobProcessed). Opt-in per class via
 * `alerts.rules.slow_p95.class_threshold_ms[$class]` — classes without an
 * entry are skipped, since "slow" is workload-dependent.
 */
final class SlowP95Detector
{
    public const string RULE = 'slow_p95';

    public function detect(string $class): ?Issue
    {
        if (! Config::bool('alerts.rules.slow_p95.enabled', false)) {
            return null;
        }

        if ($class === '') {
            return null;
        }

        $threshold = $this->thresholdFor($class);
        if ($threshold === null) {
            return null;
        }

        $samples = $this->redis()->command('lrange', [
            KeyPrefix::make("duration:samples:{$class}"),
            0,
            -1,
        ]);

        if (! is_array($samples)) {
            return null;
        }

        $nums = [];
        foreach ($samples as $s) {
            if (is_numeric($s)) {
                $nums[] = (int) $s;
            }
        }

        if (count($nums) < 10) {
            return null;
        }

        sort($nums);
        $idx = (int) ceil(0.95 * count($nums)) - 1;
        $p95 = $nums[max(0, min(count($nums) - 1, $idx))];

        if ($p95 < $threshold) {
            return null;
        }

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: '',
            queue: '',
            jobClass: $class,
            title: 'Job class p95 duration exceeded: ' . class_basename($class),
            description: "{$class} p95 duration {$p95}ms ≥ threshold {$threshold}ms.",
            context: [
                'p95_ms' => $p95,
                'threshold_ms' => $threshold,
                'sample_count' => count($nums),
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    private function thresholdFor(string $class): ?int
    {
        $map = Config::array('alerts.rules.slow_p95.class_threshold_ms');
        $value = $map[$class] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.slow_p95.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
