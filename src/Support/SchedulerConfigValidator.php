<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Hard exception on every misconfiguration shape — silent default-fallback
 * would hide typos that decide whether scheduler observability runs.
 */
final class SchedulerConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $scheduler
     */
    public static function validate(array $scheduler): void
    {
        self::validateBoolean($scheduler, 'enabled', 'queue-insights.scheduler.enabled');
        self::validateBoolean($scheduler, 'snapshot_rebuild', 'queue-insights.scheduler.snapshot_rebuild');

        self::validateCaptureBlock($scheduler['capture'] ?? null);

        self::validatePositiveInts(
            'scheduler.retention',
            $scheduler['retention'] ?? null,
            ['run_ttl_seconds', 'runs_index_max', 'aggregate_ttl_hours', 'run_jobs_max'],
        );

        self::validatePositiveInts(
            'scheduler.hung',
            $scheduler['hung'] ?? null,
            ['grace_seconds', 'min_runs_for_p95'],
        );

        self::validateSweeper($scheduler['sweeper'] ?? null);
        self::validateHeartbeat($scheduler['heartbeat'] ?? null);
        self::validateAlerts($scheduler['alerts'] ?? null);
        self::validateDashboard($scheduler['dashboard'] ?? null);
    }

    private static function validateCaptureBlock(mixed $capture): void
    {
        if ($capture === null) {
            return;
        }

        self::ensureArray($capture, 'queue-insights.scheduler.capture');
        assert(is_array($capture));

        if (array_key_exists('output', $capture)) {
            $value = $capture['output'];
            if (! is_string($value) || CaptureMode::tryFrom($value) === null) {
                throw new QueueInsightsConfigException(
                    'queue-insights.scheduler.capture.output must be one of: off, metadata, full.'
                );
            }
        }

        if (array_key_exists('max_output_bytes', $capture)) {
            $value = $capture['max_output_bytes'];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    'queue-insights.scheduler.capture.max_output_bytes must be a positive integer.'
                );
            }
        }
    }

    private static function validateSweeper(mixed $sweeper): void
    {
        if ($sweeper === null) {
            return;
        }

        self::ensureArray($sweeper, 'queue-insights.scheduler.sweeper');
        assert(is_array($sweeper));
        self::validateBoolean($sweeper, 'enabled', 'queue-insights.scheduler.sweeper.enabled');
        self::validatePositiveInts('scheduler.sweeper', $sweeper, ['sweep_seconds', 'drift_seconds']);
    }

    private static function validateHeartbeat(mixed $heartbeat): void
    {
        if ($heartbeat === null) {
            return;
        }

        self::ensureArray($heartbeat, 'queue-insights.scheduler.heartbeat');
        assert(is_array($heartbeat));
        self::validateBoolean($heartbeat, 'enabled', 'queue-insights.scheduler.heartbeat.enabled');

        if (! array_key_exists('url', $heartbeat) || $heartbeat['url'] === null) {
            return;
        }

        $url = $heartbeat['url'];
        if (! is_string($url) || $url === '') {
            throw new QueueInsightsConfigException(
                'queue-insights.scheduler.heartbeat.url must be a non-empty string or null.'
            );
        }
    }

    private static function validateAlerts(mixed $alerts): void
    {
        if ($alerts === null) {
            return;
        }

        self::ensureArray($alerts, 'queue-insights.scheduler.alerts');
        assert(is_array($alerts));
        self::validateBoolean($alerts, 'enabled', 'queue-insights.scheduler.alerts.enabled');
        self::validatePositiveInts('scheduler.alerts', $alerts, ['cooldown_seconds']);
        ChannelsConfigValidator::validate($alerts['channels'] ?? null, 'scheduler.alerts.channels');
    }

    private static function validateDashboard(mixed $dashboard): void
    {
        if ($dashboard === null) {
            return;
        }

        self::ensureArray($dashboard, 'queue-insights.scheduler.dashboard');
        assert(is_array($dashboard));
        self::validateBoolean($dashboard, 'enabled', 'queue-insights.scheduler.dashboard.enabled');
    }

    private static function ensureArray(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            throw new QueueInsightsConfigException("{$path} must be an array.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function validateBoolean(array $block, string $key, string $path): void
    {
        if (array_key_exists($key, $block) && ! is_bool($block[$key])) {
            throw new QueueInsightsConfigException("{$path} must be a boolean.");
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private static function validatePositiveInts(string $section, mixed $block, array $keys): void
    {
        if ($block === null) {
            return;
        }

        self::ensureArray($block, "queue-insights.{$section}");
        assert(is_array($block));

        foreach ($keys as $key) {
            if (! array_key_exists($key, $block)) {
                continue;
            }

            $value = $block[$key];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    "queue-insights.{$section}.{$key} must be a positive integer."
                );
            }
        }
    }
}
