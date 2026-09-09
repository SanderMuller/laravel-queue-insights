<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Validates the `schedule` block: the auto-registration toggle, the cron
 * cadence of `queue-insights:snapshot`, and the optional override of the
 * `live:*` key TTL derived from that cadence.
 */
final class ScheduleConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $schedule
     */
    public static function validate(array $schedule): void
    {
        if (isset($schedule['enabled']) && ! is_bool($schedule['enabled'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.schedule.enabled must be a boolean.'
            );
        }

        if (array_key_exists('cron', $schedule) && $schedule['cron'] !== null) {
            $cron = $schedule['cron'];
            if (! is_string($cron) || ! SnapshotCadence::isValidCron(trim($cron))) {
                throw new QueueInsightsConfigException(
                    'queue-insights.schedule.cron must be a valid cron expression (e.g. "*/15 * * * *").'
                );
            }
        }

        if (array_key_exists('live_ttl_seconds', $schedule) && $schedule['live_ttl_seconds'] !== null) {
            $ttl = $schedule['live_ttl_seconds'];
            if (! is_numeric($ttl) || (int) $ttl < 1) {
                throw new QueueInsightsConfigException(
                    'queue-insights.schedule.live_ttl_seconds must be a positive integer or null.'
                );
            }
        }
    }
}
