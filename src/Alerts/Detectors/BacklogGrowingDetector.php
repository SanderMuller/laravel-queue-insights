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
 * Catches the "depth is growing faster than workers can drain" failure mode
 * that a fixed-threshold `depth` rule misses. Skips when fewer than
 * `min_samples` data points are available so freshly cleared queues don't
 * fire on the first slope crossing. See `internal/specs/alerting.md` §6.
 */
final class BacklogGrowingDetector
{
    public const string RULE = 'backlog_growing';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! Config::bool('alerts.rules.backlog_growing.enabled', false)) {
            return null;
        }

        $minSamples = max(2, Config::int('alerts.rules.backlog_growing.min_samples', 5));
        $minSlopePerMinute = $this->minSlopePerMinute();

        $samples = $this->readSamples($connection, $canonicalQueue);
        if (count($samples) < $minSamples) {
            return null;
        }

        $slopePerSecond = $this->leastSquaresSlope($samples);
        $slopePerMinute = $slopePerSecond * 60.0;

        if ($slopePerMinute < $minSlopePerMinute) {
            return null;
        }

        $latest = $samples[count($samples) - 1];

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Queue backlog growing',
            description: sprintf(
                'Queue %s:%s depth slope is +%s/min over the last %d samples (current depth %d).',
                $connection,
                $canonicalQueue,
                number_format($slopePerMinute, 1),
                count($samples),
                $latest[1],
            ),
            context: [
                'slope_per_minute' => round($slopePerMinute, 2),
                'min_slope_per_minute' => $minSlopePerMinute,
                'samples' => count($samples),
                'min_samples' => $minSamples,
                'latest_depth' => $latest[1],
                'window_seconds' => $latest[0] - $samples[0][0],
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    /**
     * @return list<array{0: int, 1: int}>  list of [ts, depth] pairs sorted by ts ascending
     */
    private function readSamples(string $connection, string $canonicalQueue): array
    {
        $key = KeyPrefix::make("samples:depth:{$connection}:{$canonicalQueue}");
        $rows = $this->redis()->command('zrange', [$key, 0, -1, ['WITHSCORES' => true]]);

        if (! is_array($rows)) {
            return [];
        }

        $samples = [];
        foreach ($rows as $member => $score) {
            if (! is_string($member)) {
                continue;
            }

            $colon = strpos($member, ':');
            if ($colon === false) {
                continue;
            }

            $depthRaw = substr($member, $colon + 1);
            if (! is_numeric($depthRaw)) {
                continue;
            }

            if (! is_numeric($score)) {
                continue;
            }

            $samples[] = [(int) $score, (int) $depthRaw];
        }

        return $samples;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $samples
     */
    private function leastSquaresSlope(array $samples): float
    {
        $n = count($samples);
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        // Re-base x on the first timestamp so float precision stays sane —
        // raw unix seconds × N would burn ~10 significant digits before
        // arithmetic.
        $x0 = $samples[0][0];

        foreach ($samples as $sample) {
            $x = (float) ($sample[0] - $x0);
            $y = (float) $sample[1];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
    }

    private function minSlopePerMinute(): float
    {
        $value = config('queue-insights.alerts.rules.backlog_growing.min_slope_per_minute', 50.0);

        return is_int($value) || is_float($value) ? (float) $value : 50.0;
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.backlog_growing.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
