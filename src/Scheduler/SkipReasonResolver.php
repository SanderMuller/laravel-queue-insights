<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Best-effort post-hoc skip reason. Laravel's
 * `ScheduledTaskSkipped` event carries no reason so we re-run the
 * filter checks against the same surfaces (`framework/schedule-*`
 * mutex cache keys, maintenance flag, cron expression).
 *
 * Listed in priority order; first match wins. The dashboard should
 * label the result as a guess (the real cause may have been a
 * `when()` / `skip()` callback whose state we can't introspect).
 */
final class SkipReasonResolver
{
    /**
     * @return 'mutex'|'one_server'|'maintenance'|'between'|'filter'
     */
    public static function resolve(Event $task, Application $app): string
    {
        if ((bool) $task->withoutOverlapping && self::mutexHeld($task)) {
            return 'mutex';
        }

        if ((bool) $task->onOneServer && self::otherServerWon($task)) {
            return 'one_server';
        }

        if (! (bool) $task->evenInMaintenanceMode && $app->isDownForMaintenance()) {
            return 'maintenance';
        }

        if (! self::expressionDueNow($task)) {
            return 'between';
        }

        return 'filter';
    }

    private static function mutexHeld(Event $task): bool
    {
        try {
            return Cache::has($task->mutexName());
        } catch (Throwable) {
            return false;
        }
    }

    private static function otherServerWon(Event $task): bool
    {
        try {
            // `onOneServer` adds a `lock-` prefix sibling to the mutex name.
            // Conservatively check both forms — mismatch is harmless.
            if (Cache::has($task->mutexName())) {
                return true;
            }

            return Cache::has('lock-' . $task->mutexName());
        } catch (Throwable) {
            return false;
        }
    }

    private static function expressionDueNow(Event $task): bool
    {
        try {
            $cron = new CronExpression(is_string($task->expression) ? $task->expression : '* * * * *');
            $tz = $task->timezone;
            if ($tz instanceof DateTimeZone) {
                $tz = $tz->getName();
            }

            $tzString = is_string($tz) && $tz !== '' ? $tz : null;

            return $cron->isDue('now', $tzString);
        } catch (Throwable) {
            return true;
        }
    }
}
