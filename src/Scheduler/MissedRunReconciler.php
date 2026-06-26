<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
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
        private IssueDispatcher $dispatcher,
    ) {}

    /**
     * @return int  count of missed runs flagged this sweep
     */
    public function reconcile(Schedule $schedule, ?int $nowMs = null): int
    {
        $nowMs ??= Date::now()->getTimestampMs();
        $lastSwept = $this->lastSweptMs() ?? ($nowMs - 120_000); // first sweep → look back 2 minutes
        $driftMs = Config::int('scheduler.sweeper.drift_seconds', 90) * 1000;

        // Grace gate: judge a fire missed only once its `+drift` grace has
        // elapsed (`now >= expectedAt + drift`), so a late-but-within-drift
        // `Starting` — Vapor cold start, EventBridge jitter — has had its
        // full chance to land before we call the fire missed. Without this
        // the sweep at expectedAt+3s declares missed before the (still
        // valid) Starting arrives, writing a false row.
        //
        // The window is therefore shifted back one drift. Its lower bound
        // reaches back a further drift to re-cover the band the previous
        // tick deferred (last tick judged up to lastSwept - drift). The
        // synthetic `missed`/`Starting` dedup in $actual makes the one-tick
        // boundary overlap harmless. `last_swept_ms` still advances to
        // nowMs untouched so the liveness gauge keeps reading "now".
        $evalFrom = $lastSwept - $driftMs;
        $evalTo = $nowMs - $driftMs;

        $missedCount = 0;
        foreach ($schedule->events() as $event) {
            try {
                $missedCount += $this->reconcileEvent($event, $evalFrom, $evalTo, $driftMs);
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

        // Debounce: alert only after this many consecutive expected fires
        // have gone unobserved. A single isolated miss is infra noise on a
        // per-minute scheduler (late tick / dropped Starting write), so the
        // row is recorded but the alert is gated. `$observed` excludes prior
        // synthetic `missed` rows so a sustained gap actually accumulates; it
        // loads lazily on the first miss so a healthy sweep stays cheap.
        $minConsecutive = max(1, Config::int('scheduler.sweeper.min_consecutive_misses', 2));
        $observed = null;

        $missed = 0;
        foreach ($expected as $expectedAt) {
            if ($this->anyWithinDrift($expectedAt, $actual, $driftMs)) {
                continue;
            }

            $this->store->recordMissed(
                $taskKey,
                (string) Str::ulid(),
                $expectedAt,
            );

            ++$missed;

            if ($minConsecutive <= 1) {
                $this->dispatcher->dispatchScheduledTaskMissed($taskKey, $event, $expectedAt);

                continue;
            }

            $observed ??= $this->reader->observedRunTimestampsBetween(
                $taskKey,
                $this->lookbackFloorMs($cron, $tzString, $expected[0], $minConsecutive) - $driftMs,
                $toMs + $driftMs,
            );

            if ($this->consecutiveMissCount($cron, $tzString, $expectedAt, $observed, $driftMs, $minConsecutive) >= $minConsecutive) {
                $this->dispatcher->dispatchScheduledTaskMissed($taskKey, $event, $expectedAt);
            }
        }

        return $missed;
    }

    /**
     * Earliest expected-fire timestamp the gate may need to inspect:
     * `$minConsecutive - 1` cron fires before the first fire in this sweep.
     * Bounds the `observed` query window so a sustained gap that began
     * before the sweep window is still visible to the look-back.
     */
    private function lookbackFloorMs(CronExpression $cron, ?string $tz, int $firstExpectedMs, int $minConsecutive): int
    {
        $cursorMs = $firstExpectedMs;
        for ($i = 1; $i < $minConsecutive; ++$i) {
            $prevMs = $this->previousFireMs($cron, $tz, $cursorMs);
            if ($prevMs === null) {
                break;
            }

            $cursorMs = $prevMs;
        }

        return $cursorMs;
    }

    /**
     * Length of the unbroken run of unobserved expected fires ending at
     * (and including) `$expectedAtMs`. Walks backwards fire-by-fire and
     * stops at the first fire with an observed run within drift, or once
     * the threshold is reached (no need to count further).
     *
     * @param  list<int>  $observed
     */
    private function consecutiveMissCount(CronExpression $cron, ?string $tz, int $expectedAtMs, array $observed, int $driftMs, int $cap): int
    {
        $count = 1;
        $cursorMs = $expectedAtMs;
        for ($i = 1; $i < $cap; ++$i) {
            $prevMs = $this->previousFireMs($cron, $tz, $cursorMs);
            if ($prevMs === null) {
                break;
            }

            if ($this->anyWithinDrift($prevMs, $observed, $driftMs)) {
                break;
            }

            ++$count;
            $cursorMs = $prevMs;
        }

        return $count;
    }

    /**
     * The cron fire strictly before `$fromMs`, in unix milliseconds, or
     * null when the expression yields none.
     */
    private function previousFireMs(CronExpression $cron, ?string $tz, int $fromMs): ?int
    {
        $fromSec = (int) floor($fromMs / 1000);

        try {
            $prev = $cron->getPreviousRunDate(Date::createFromTimestamp($fromSec)->toDateTimeString(), 0, false, $tz);
        } catch (Throwable) {
            return null;
        }

        return $prev->getTimestamp() * 1000;
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
