<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Events\ScheduledTaskMissed;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * Compares each task's expected fires (from its cron expression)
 * since the last sweep to the actual `Starting` events recorded.
 * Drift > `sweeper.drift_seconds` → record a synthetic `missed`
 * run + dispatch `ScheduledTaskMissed` (cooldown-gated).
 *
 * Why per-fire matching: a task firing every minute that misses one
 * minute should produce one missed-run row, not "every fire after that
 * misalignment is missed". Fire-by-fire matching against `±drift_seconds`
 * keeps the row count bounded and meaningful.
 */
final readonly class MissedRunReconciler
{
    private const string LAST_SWEPT_KEY_SUFFIX = 'sched:sweeper:last_swept_ms';

    public function __construct(
        private RunStore $store,
        private ScheduleReader $reader,
        private SchedulerCooldown $cooldown,
    ) {}

    /**
     * @return int  count of missed runs flagged this sweep
     */
    public function reconcile(Schedule $schedule, ?int $nowMs = null): int
    {
        $nowMs ??= Date::now()->getTimestampMs();
        $lastSwept = $this->lastSweptMs() ?? ($nowMs - 120_000); // first sweep → look back 2 minutes
        $driftMs = Config::int('scheduler.sweeper.drift_seconds', 90) * 1000;

        $missedCount = 0;
        foreach ($schedule->events() as $event) {
            try {
                $missedCount += $this->reconcileEvent($event, $lastSwept, $nowMs, $driftMs);
            } catch (Throwable $throwable) {
                Log::warning('queue-insights: MissedRunReconciler::reconcileEvent failed', [
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        $this->writeLastSweptMs($nowMs);

        return $missedCount;
    }

    private function reconcileEvent(Event $event, int $fromMs, int $toMs, int $driftMs): int
    {
        $expression = is_string($event->expression) && $event->expression !== '' ? $event->expression : '* * * * *';
        $cron = new CronExpression($expression);

        $tz = $event->timezone;
        if ($tz instanceof DateTimeZone) {
            $tz = $tz->getName();
        }

        $tzString = is_string($tz) && $tz !== '' ? $tz : null;

        $expected = $this->fireTimesBetween($cron, $tzString, $fromMs, $toMs);
        if ($expected === []) {
            return 0;
        }

        $taskKey = TaskKey::for($event);
        // Pull a wider window than just $expected so a `Starting` recorded
        // slightly outside the window can still match a fire near a
        // boundary.
        $actual = $this->reader->startingTimestampsBetween(
            $taskKey,
            $fromMs - $driftMs,
            $toMs + $driftMs,
        );

        $missed = 0;
        foreach ($expected as $expectedAt) {
            if (! $this->anyWithinDrift($expectedAt, $actual, $driftMs)) {
                $this->store->recordMissed(
                    $taskKey,
                    (string) Str::ulid(),
                    $expectedAt,
                );

                if ($this->cooldown->acquire('missed', $taskKey)) {
                    EventDispatcher::dispatch(new ScheduledTaskMissed($taskKey, $event, $expectedAt));
                }

                ++$missed;
            }
        }

        return $missed;
    }

    /**
     * @return list<int> unix milliseconds of every fire in [fromMs, toMs]
     */
    private function fireTimesBetween(CronExpression $cron, ?string $tz, int $fromMs, int $toMs): array
    {
        $fromSec = (int) floor($fromMs / 1000);
        $toSec = (int) ceil($toMs / 1000);
        $cursor = Date::createFromTimestamp($fromSec);

        $out = [];
        // Hard cap so a misconfigured expression can't run away. 1440
        // covers every-minute over 24h — far more than `sweep_seconds`
        // ever needs.
        for ($i = 0; $i < 1440; ++$i) {
            try {
                $next = $cron->getNextRunDate($cursor->toDateTimeString(), 0, false, $tz);
            } catch (Throwable) {
                break;
            }

            $nextTs = $next->getTimestamp();
            if ($nextTs > $toSec) {
                break;
            }

            $out[] = $nextTs * 1000;
            $cursor = Date::createFromTimestamp($nextTs + 1);
        }

        return $out;
    }

    /**
     * @param  list<int>  $actual
     */
    private function anyWithinDrift(int $expectedAtMs, array $actual, int $driftMs): bool
    {
        foreach ($actual as $startedAtMs) {
            if (abs($startedAtMs - $expectedAtMs) <= $driftMs) {
                return true;
            }
        }

        return false;
    }

    private function lastSweptMs(): ?int
    {
        $value = Redis::connection(Config::string('redis_connection', 'default'))
            ->command('get', [KeyPrefix::make(self::LAST_SWEPT_KEY_SUFFIX)]);

        return is_numeric($value) ? (int) $value : null;
    }

    private function writeLastSweptMs(int $nowMs): void
    {
        Redis::connection(Config::string('redis_connection', 'default'))
            ->command('setex', [
                KeyPrefix::make(self::LAST_SWEPT_KEY_SUFFIX),
                3600,
                (string) $nowMs,
            ]);
    }
}
