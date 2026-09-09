<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Cron\CronExpression;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Cadence of the auto-registered `queue-insights:snapshot` (and the
 * scheduler sweeper), plus everything derived from it.
 *
 * Why configurable: on scale-to-zero hosts (Laravel Cloud, Vapor) every
 * scheduled invocation wakes the app container, so a hardcoded
 * `->everyMinute()` pins the app awake forever and bills for it. Hosts
 * that value cost over freshness dial the cadence down; the default
 * stays `* * * * *` so existing installs are untouched.
 *
 * The `live:depth:{c}:{q}` SETEX TTL is derived from the cadence rather
 * than hardcoded at 90 s — otherwise a 15-minute cadence would leave the
 * live keys expired most of the time, blanking the dashboard tiles,
 * permanently firing the dashboard's snapshot watchdog banner and making
 * `queue_insights_snapshot_alive` read dead.
 */
final class SnapshotCadence
{
    public const string DEFAULT_CRON = '* * * * *';

    /** Floor kept at the pre-tunable value so per-minute hosts see no change. */
    private const int MIN_LIVE_TTL_SECONDS = 90;

    /** Head-room over one cadence period for a slow snapshot pass. */
    private const int LIVE_TTL_GRACE_SECONDS = 30;

    /** Sanity ceiling on a derived TTL — comfortably past a monthly cadence. */
    private const int MAX_TTL_SECONDS = 3456000;

    public static function snapshotCron(): string
    {
        return self::normalise(Config::string('schedule.cron', self::DEFAULT_CRON));
    }

    public static function sweepCron(): string
    {
        return self::normalise(Config::string('scheduler.sweeper.cron', self::DEFAULT_CRON));
    }

    /**
     * TTL for the `live:*` keys the snapshot command writes. Explicit
     * `schedule.live_ttl_seconds` wins; otherwise the seconds remaining
     * until the next scheduled fire plus grace, floored at the historical
     * 90 s.
     *
     * Computed against "now" rather than from an average period because a
     * gapped expression (`* 9-17 * * 1-5`) has no single period: sizing it
     * on the one-minute in-hours gap would let the live keys expire over
     * the weekend and flap the watchdog through every idle stretch.
     */
    public static function liveTtlSeconds(): int
    {
        $configured = Config::int('schedule.live_ttl_seconds', 0);
        if ($configured > 0) {
            return min($configured, self::MAX_TTL_SECONDS);
        }

        $ttl = self::secondsUntilNextFire(self::snapshotCron()) + self::LIVE_TTL_GRACE_SECONDS;

        return min(max($ttl, self::MIN_LIVE_TTL_SECONDS), self::MAX_TTL_SECONDS);
    }

    /**
     * Seconds from now until `$cron` next fires, evaluated in the
     * scheduler's own timezone (`app.schedule_timezone`, falling back to
     * `app.timezone`) — the same clock Laravel fires the event on, so a
     * host running PHP in UTC with a local schedule timezone doesn't
     * expire its keys hours early.
     *
     * Falls back to 60 for an unparsable expression — the validator
     * rejects those at boot, so this only guards hosts that skipped
     * validation.
     */
    public static function secondsUntilNextFire(string $cron, ?int $nowTs = null): int
    {
        $nowTs ??= Date::now()->getTimestamp();

        try {
            $next = (new CronExpression($cron))
                ->getNextRunDate('@' . $nowTs, 0, false, self::scheduleTimezone())
                ->getTimestamp();
        } catch (Throwable) {
            return 60;
        }

        return max($next - $nowTs, 1);
    }

    private static function scheduleTimezone(): ?string
    {
        $timezone = config('app.schedule_timezone', config('app.timezone'));

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    public static function isValidCron(string $cron): bool
    {
        return $cron !== '' && CronExpression::isValidExpression($cron);
    }

    private static function normalise(string $cron): string
    {
        $cron = trim($cron);

        return $cron === '' ? self::DEFAULT_CRON : $cron;
    }
}
