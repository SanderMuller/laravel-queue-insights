<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Alerts-block validator. Extracted from `ConfigValidator` to keep that
 * class under PHPStan's cognitive-complexity ceiling — eight per-rule
 * shapes + three channel shapes + the legacy-thresholds branch were
 * pushing the parent class to 94 (limit 80).
 *
 * Logs (but does not throw) when the legacy top-level `alerts.thresholds`
 * key is in use, since `mergeConfigFrom` shallow merging means we must
 * keep honouring it for hosts that published config before the
 * `alerts.rules` migration. Legacy wins; the warning is the migration
 * nudge.
 *
 * @internal
 */
final class AlertsConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $alerts
     */
    public static function validate(array $alerts): void
    {
        self::validateRoot($alerts);
        self::validateLegacyThresholds($alerts);
        self::validateRules($alerts);
        self::validateChannels($alerts);
    }

    /**
     * @param  array<array-key, mixed>  $alerts
     */
    private static function validateRoot(array $alerts): void
    {
        if (isset($alerts['enabled']) && ! is_bool($alerts['enabled'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.alerts.enabled must be a boolean.'
            );
        }

        if (isset($alerts['cooldown_seconds'])) {
            $cooldown = $alerts['cooldown_seconds'];
            if (! is_int($cooldown) || $cooldown < 0) {
                throw new QueueInsightsConfigException(
                    'queue-insights.alerts.cooldown_seconds must be a non-negative integer.'
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $alerts
     */
    private static function validateLegacyThresholds(array $alerts): void
    {
        $legacyThresholds = isset($alerts['thresholds']) && is_array($alerts['thresholds'])
            ? $alerts['thresholds']
            : [];

        if ($legacyThresholds === []) {
            return;
        }

        self::validateDepthThresholds($legacyThresholds, 'alerts.thresholds');

        Log::warning('queue-insights: legacy `alerts.thresholds` config key is deprecated. Move entries under `alerts.rules.depth.thresholds`. Legacy entries take precedence until removed.');
    }

    /**
     * @param  array<array-key, mixed>  $alerts
     */
    private static function validateRules(array $alerts): void
    {
        $rules = isset($alerts['rules']) && is_array($alerts['rules']) ? $alerts['rules'] : [];

        self::validateDepthRule($rules['depth'] ?? null);
        self::validateStalledRule($rules['stalled'] ?? null);
        self::validateOldestPendingRule($rules['oldest_pending'] ?? null);
        self::validateStuckInFlightRule($rules['stuck_inflight'] ?? null);
        self::validateFailureRateRule($rules['failure_rate'] ?? null);
        self::validateSlowP95Rule($rules['slow_p95'] ?? null);
        self::validateSnapshotErroredRule($rules['snapshot_errored'] ?? null);
        self::validateBacklogGrowingRule($rules['backlog_growing'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $alerts
     */
    private static function validateChannels(array $alerts): void
    {
        $channels = isset($alerts['channels']) && is_array($alerts['channels']) ? $alerts['channels'] : [];
        self::validateLogChannel($channels['log'] ?? null);
        self::validateSlackChannel($channels['slack'] ?? null);
        self::validateMailChannel($channels['mail'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $thresholds
     */
    private static function validateDepthThresholds(array $thresholds, string $path): void
    {
        foreach ($thresholds as $i => $entry) {
            if (! is_array($entry)) {
                throw new QueueInsightsConfigException("queue-insights.{$path}[{$i}] must be an array.");
            }

            if (! isset($entry['connection']) || ! is_string($entry['connection']) || $entry['connection'] === '') {
                throw new QueueInsightsConfigException("queue-insights.{$path}[{$i}].connection must be a non-empty string.");
            }

            if (! isset($entry['queue']) || ! is_string($entry['queue']) || $entry['queue'] === '') {
                throw new QueueInsightsConfigException("queue-insights.{$path}[{$i}].queue must be a non-empty string.");
            }

            if (! isset($entry['depth']) || ! is_int($entry['depth']) || $entry['depth'] < 0) {
                throw new QueueInsightsConfigException("queue-insights.{$path}[{$i}].depth must be a non-negative integer.");
            }

            if (isset($entry['severity'])) {
                self::assertSeverity($entry['severity'], "{$path}[{$i}].severity");
            }
        }
    }

    private static function validateDepthRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.depth must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.depth.enabled');

        $thresholds = $rule['thresholds'] ?? [];
        if (! is_array($thresholds)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.depth.thresholds must be an array.');
        }

        self::validateDepthThresholds($thresholds, 'alerts.rules.depth.thresholds');
    }

    private static function validateStalledRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.stalled must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.stalled.enabled');
        self::assertOptionalPositiveInt($rule, 'idle_seconds', 'alerts.rules.stalled.idle_seconds');
        self::assertOptionalNonNegativeInt($rule, 'min_depth', 'alerts.rules.stalled.min_depth');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.stalled.severity');
    }

    private static function validateOldestPendingRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.oldest_pending must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.oldest_pending.enabled');
        self::assertOptionalPositiveInt($rule, 'seconds', 'alerts.rules.oldest_pending.seconds');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.oldest_pending.severity');
    }

    private static function validateStuckInFlightRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.stuck_inflight must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.stuck_inflight.enabled');
        self::assertOptionalPositiveInt($rule, 'seconds', 'alerts.rules.stuck_inflight.seconds');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.stuck_inflight.severity');
    }

    private static function validateFailureRateRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.failure_rate must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.failure_rate.enabled');
        self::assertOptionalPositiveInt($rule, 'min_jobs', 'alerts.rules.failure_rate.min_jobs');

        if (isset($rule['ratio'])) {
            $ratio = $rule['ratio'];
            if ((! is_int($ratio) && ! is_float($ratio)) || $ratio < 0 || $ratio > 1) {
                throw new QueueInsightsConfigException('queue-insights.alerts.rules.failure_rate.ratio must be a number between 0 and 1.');
            }
        }

        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.failure_rate.severity');
    }

    private static function validateSlowP95Rule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.slow_p95 must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.slow_p95.enabled');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.slow_p95.severity');

        $map = $rule['class_threshold_ms'] ?? [];
        if (! is_array($map)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.slow_p95.class_threshold_ms must be an array.');
        }

        foreach ($map as $class => $threshold) {
            if (! is_string($class) || $class === '') {
                throw new QueueInsightsConfigException('queue-insights.alerts.rules.slow_p95.class_threshold_ms keys must be non-empty class names.');
            }

            if (! is_int($threshold) || $threshold < 0) {
                throw new QueueInsightsConfigException("queue-insights.alerts.rules.slow_p95.class_threshold_ms[{$class}] must be a non-negative integer.");
            }
        }
    }

    private static function validateSnapshotErroredRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.snapshot_errored must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.snapshot_errored.enabled');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.snapshot_errored.severity');
    }

    private static function validateBacklogGrowingRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.rules.backlog_growing must be an array.');
        }

        self::assertOptionalBool($rule, 'enabled', 'alerts.rules.backlog_growing.enabled');
        self::assertOptionalSeverity($rule, 'severity', 'alerts.rules.backlog_growing.severity');
        self::assertOptionalPositiveInt($rule, 'min_samples', 'alerts.rules.backlog_growing.min_samples');

        if (isset($rule['min_slope_per_minute'])) {
            $slope = $rule['min_slope_per_minute'];
            if ((! is_int($slope) && ! is_float($slope)) || $slope <= 0) {
                throw new QueueInsightsConfigException(
                    'queue-insights.alerts.rules.backlog_growing.min_slope_per_minute must be a positive number.'
                );
            }
        }
    }

    private static function validateLogChannel(mixed $channel): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.channels.log must be an array.');
        }

        self::assertOptionalBool($channel, 'enabled', 'alerts.channels.log.enabled');

        if (isset($channel['level']) && ! is_string($channel['level'])) {
            throw new QueueInsightsConfigException('queue-insights.alerts.channels.log.level must be a string.');
        }
    }

    private static function validateSlackChannel(mixed $channel): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.channels.slack must be an array.');
        }

        self::assertOptionalBool($channel, 'enabled', 'alerts.channels.slack.enabled');

        $enabled = $channel['enabled'] ?? false;
        if ($enabled === true) {
            $url = $channel['webhook_url'] ?? null;
            if (! is_string($url) || $url === '') {
                throw new QueueInsightsConfigException(
                    'queue-insights.alerts.channels.slack.webhook_url must be a non-empty string when slack is enabled.'
                );
            }
        }
    }

    private static function validateMailChannel(mixed $channel): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException('queue-insights.alerts.channels.mail must be an array.');
        }

        self::assertOptionalBool($channel, 'enabled', 'alerts.channels.mail.enabled');

        $enabled = $channel['enabled'] ?? false;
        if ($enabled === true) {
            $to = $channel['to'] ?? null;
            if (! is_array($to) || $to === []) {
                throw new QueueInsightsConfigException(
                    'queue-insights.alerts.channels.mail.to must be a non-empty array when mail is enabled.'
                );
            }

            foreach ($to as $i => $address) {
                if (! is_string($address) || $address === '') {
                    throw new QueueInsightsConfigException(
                        "queue-insights.alerts.channels.mail.to[{$i}] must be a non-empty string."
                    );
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $bag
     */
    private static function assertOptionalBool(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag)) {
            return;
        }

        if (! is_bool($bag[$key])) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be a boolean.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $bag
     */
    private static function assertOptionalPositiveInt(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag)) {
            return;
        }

        $value = $bag[$key];
        if (! is_int($value) || $value < 1) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be a positive integer.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $bag
     */
    private static function assertOptionalNonNegativeInt(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag)) {
            return;
        }

        $value = $bag[$key];
        if (! is_int($value) || $value < 0) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be a non-negative integer.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $bag
     */
    private static function assertOptionalSeverity(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag)) {
            return;
        }

        self::assertSeverity($bag[$key], $path);
    }

    private static function assertSeverity(mixed $value, string $path): void
    {
        if (! is_string($value) || AlertSeverity::tryFrom($value) === null) {
            throw new QueueInsightsConfigException(
                "queue-insights.{$path} must be one of: warning, critical."
            );
        }
    }
}
