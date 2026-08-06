<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Events\ScheduledTaskFailed as DomainScheduledTaskFailed;
use SanderMuller\QueueInsights\Events\ScheduledTaskHung;
use SanderMuller\QueueInsights\Events\ScheduledTaskMissed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledBackgroundTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\HungTaskReconciler;
use SanderMuller\QueueInsights\Scheduler\MissedRunReconciler;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
    config()->set('queue-insights.scheduler.sweeper.enabled', true);
    config()->set('queue-insights.scheduler.alerts.enabled', true);
    config()->set('queue-insights.scheduler.alerts.cooldown_seconds', 900);
});

function makeMutexEvent(string $command = 'php artisan demo:run', string $expression = '* * * * *'): ScheduleEvent
{
    $mutex = resolve(CacheEventMutex::class);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = $expression;

    return $event;
}

function resolveSchedule(): Schedule
{
    return resolve(Schedule::class);
}

/**
 * Narrow the live Schedule down to a single event so the reconciler's only
 * candidate is the one under test — the container auto-registers
 * queue-insights:snapshot at boot, which beforeEach can't undo.
 */
function keepOnlyEvent(Schedule $schedule, ScheduleEvent $event): void
{
    $only = collect($schedule->events())->filter(
        fn (ScheduleEvent $e): bool => TaskKey::for($e) === TaskKey::for($event),
    )->values();

    $reflection = new ReflectionProperty($schedule, 'events');
    $reflection->setValue($schedule, $only->all());
}

it('flags an expected fire as missed when no Starting was recorded', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    $schedule = resolveSchedule();
    $schedule->command('demo:run')->everyMinute();

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $missed = $reconciler->reconcile($schedule);

    expect($missed)->toBeGreaterThanOrEqual(1);
    EventDispatcher::assertDispatched(ScheduledTaskMissed::class);
});

it('does not flag missed when a Starting landed late but within the drift window', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);

    $schedule = resolveSchedule();
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    // now=12:10:00; with grace gate the judged horizon is now-90s=12:08:30
    // and the window lower bound is (lastSwept 12:06:40)-90s=12:05:10, so
    // the only judged fire is 12:06:00 (12:09 is past the horizon).
    $now = Date::createFromTimestamp(strtotime('2026-06-26 12:10:00 UTC'));
    $nowMs = $now->getTimestampMs();
    $fireMs = strtotime('2026-06-26 12:06:00 UTC') * 1000;

    // Starting landed 40s late — within the 90s drift of the 12:06 fire.
    (new RunStore())->recordStarting([
        'task_key' => TaskKey::for($event),
        'run_id' => (string) Str::ulid(),
        'started_at_ms' => $fireMs + 40_000,
        'host_id' => 'host-a',
        'is_background' => false,
        'expected_finish_at_ms' => $fireMs + 100_000,
    ]);

    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($nowMs - 200_000));

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $missed = $reconciler->reconcile($schedule, $nowMs);

    expect($missed)->toBe(0);
    EventDispatcher::assertNotDispatched(ScheduledTaskMissed::class);
});

it('defers an expected fire until its drift grace elapses, then a within-drift Starting suppresses it', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);
    config()->set('queue-insights.scheduler.sweeper.min_consecutive_misses', 1); // isolate the grace behaviour

    $schedule = resolveSchedule();
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    $fireMs = strtotime('2026-06-26 12:06:00 UTC') * 1000;
    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));

    // Phase 1: sweep at fire+5s, before the (cold-starting) Starting lands.
    // The fire is inside the deferred band (> now-90s) so it is NOT judged.
    $earlySweepMs = $fireMs + 5_000;
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($earlySweepMs - 30_000));
    $earlyMissed = $reconciler->reconcile($schedule, $earlySweepMs);

    expect($earlyMissed)->toBe(0); // pre-grace: no premature missed row
    EventDispatcher::assertNotDispatched(ScheduledTaskMissed::class);

    // The Starting arrives 40s after the fire — late, but within drift.
    (new RunStore())->recordStarting([
        'task_key' => TaskKey::for($event),
        'run_id' => (string) Str::ulid(),
        'started_at_ms' => $fireMs + 40_000,
        'host_id' => 'host-a',
        'is_background' => false,
        'expected_finish_at_ms' => $fireMs + 100_000,
    ]);

    // Phase 2: a later sweep, now past the fire's grace. It judges 12:06 and
    // finds the within-drift Starting → not missed.
    $lateMissed = $reconciler->reconcile($schedule, $fireMs + 120_000);

    expect($lateMissed)->toBe(0);
    EventDispatcher::assertNotDispatched(ScheduledTaskMissed::class);
});

it('records the missed row but does NOT alert for an isolated single-tick gap', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);
    config()->set('queue-insights.scheduler.sweeper.min_consecutive_misses', 2);

    $schedule = resolveSchedule();
    // every-3-min so the 180s interval exceeds the 90s drift window — a
    // run on the previous fire cannot "cover" the current expected fire.
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    // now=12:10:00 → grace horizon 12:08:30, window from 12:05:10: the only
    // judged fire is 12:06:00, with predecessor 12:03:00.
    $now = Date::createFromTimestamp(strtotime('2026-06-26 12:10:00 UTC'));
    $nowMs = $now->getTimestampMs();
    $prevFireMs = strtotime('2026-06-26 12:03:00 UTC') * 1000; // predecessor fire

    // Predecessor fire ran (observed); the judged fire (12:06) did not.
    (new RunStore())->recordStarting([
        'task_key' => TaskKey::for($event),
        'run_id' => (string) Str::ulid(),
        'started_at_ms' => $prevFireMs,
        'host_id' => 'host-a',
        'is_background' => false,
        'expected_finish_at_ms' => $prevFireMs + 60_000,
    ]);

    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($nowMs - 200_000));

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $missed = $reconciler->reconcile($schedule, $nowMs);

    // The synthetic row is still written for the dashboard...
    expect($missed)->toBeGreaterThanOrEqual(1);
    // ...but the predecessor ran, so the gap is 1 < threshold → no alert.
    EventDispatcher::assertNotDispatched(ScheduledTaskMissed::class);
});

it('alerts once consecutive misses reach the threshold', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);
    config()->set('queue-insights.scheduler.sweeper.min_consecutive_misses', 2);

    $schedule = resolveSchedule();
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    $now = Date::createFromTimestamp(strtotime('2026-06-26 12:10:00 UTC'));
    $nowMs = $now->getTimestampMs();

    // No observed run anywhere — the judged fire (12:06) misses and its
    // predecessor (12:03) is also unobserved, so the gap reaches 2 → alert.
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($nowMs - 200_000));

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $reconciler->reconcile($schedule, $nowMs);

    EventDispatcher::assertDispatched(ScheduledTaskMissed::class);
});

it('does not write a second missed row when a fire is re-judged across overlapping sweeps', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);
    config()->set('queue-insights.scheduler.sweeper.min_consecutive_misses', 1);

    $schedule = resolveSchedule();
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    $fireMs = strtotime('2026-06-26 12:06:00 UTC') * 1000;
    $taskKey = TaskKey::for($event);
    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));

    // Sweep 1 judges the 12:06 fire (no run) → one synthetic missed row.
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($fireMs - 30_000));
    $reconciler->reconcile($schedule, $fireMs + 100_000);
    expect(R::int('zcard', "qmtest:sched:runs:{$taskKey}"))->toBe(1);

    // Force a second sweep whose window re-includes the same fire. The prior
    // missed row (still in sched:runs, returned status-agnostically by
    // startingTimestampsBetween) matches within drift → no duplicate row.
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($fireMs + 1_000));
    $reconciler->reconcile($schedule, $fireMs + 100_000);

    expect(R::int('zcard', "qmtest:sched:runs:{$taskKey}"))->toBe(1);
});

it('honours a higher threshold — two consecutive misses do not alert when min_consecutive_misses is 3', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);
    config()->set('queue-insights.scheduler.sweeper.min_consecutive_misses', 3);

    $schedule = resolveSchedule();
    $event = $schedule->command('demo:run')->everyThreeMinutes();
    keepOnlyEvent($schedule, $event);

    // Judged fire = 12:06; predecessors 12:03 (missed) and 12:00 (ran). The
    // streak is 2, below the threshold of 3, so the deeper walk-back must
    // break at 12:00 and suppress the alert.
    $now = Date::createFromTimestamp(strtotime('2026-06-26 12:10:00 UTC'));
    $nowMs = $now->getTimestampMs();
    $observedFireMs = strtotime('2026-06-26 12:00:00 UTC') * 1000;

    (new RunStore())->recordStarting([
        'task_key' => TaskKey::for($event),
        'run_id' => (string) Str::ulid(),
        'started_at_ms' => $observedFireMs,
        'host_id' => 'host-a',
        'is_background' => false,
        'expected_finish_at_ms' => $observedFireMs + 60_000,
    ]);

    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($nowMs - 200_000));

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $missed = $reconciler->reconcile($schedule, $nowMs);

    expect($missed)->toBeGreaterThanOrEqual(1);
    EventDispatcher::assertNotDispatched(ScheduledTaskMissed::class);
});

it('flags a hung run + dispatches ScheduledTaskHung', function (): void {
    EventDispatcher::fake([ScheduledTaskHung::class]);

    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);

    // Force expected_finish_at_ms into the past so the reconciler flags it.
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'expected_finish_at_ms', '1');

    $schedule = resolveSchedule();
    $schedule->command('demo:run')->everyMinute();

    $reconciler = new HungTaskReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));
    $count = $reconciler->reconcile($schedule);

    expect($count)->toBe(1);
    EventDispatcher::assertDispatched(ScheduledTaskHung::class);
    expect(R::int('exists', "qmtest:sched:running:{$taskKey}"))->toBe(0);
});

it('respects cooldown — repeat sweeps do not double-fire ScheduledTaskHung', function (): void {
    EventDispatcher::fake([ScheduledTaskHung::class]);

    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'expected_finish_at_ms', '1');

    $schedule = resolveSchedule();
    $schedule->command('demo:run')->everyMinute();
    $reconciler = new HungTaskReconciler(new RunStore(), new ScheduleReader(), resolve(IssueDispatcher::class));

    $reconciler->reconcile($schedule);

    // Re-arm a fresh hung run for the same task; cooldown should suppress
    // the second event.
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'expected_finish_at_ms', '1');
    $reconciler->reconcile($schedule);

    EventDispatcher::assertDispatchedTimes(ScheduledTaskHung::class, 1);
});

it('Finished after a hung mark flips status + stamps recovered_from_hung', function (): void {
    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);
    $runId = (string) R::str('hget', "qmtest:sched:running:{$taskKey}", 'run_id');

    // Mark the run hung directly via RunStore.
    (new RunStore())->recordHung($taskKey, $runId);
    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('hung');

    // Re-create the running pointer so the Finished listener can find the run id.
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'run_id', $runId);
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'started_at_ms', '1');
    R::raw('hset', "qmtest:sched:running:{$taskKey}", 'expected_finish_at_ms', '1');

    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.5));

    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('success')
        ->and(R::int('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'recovered_from_hung'))->toBe(1);
});

it('dispatches ScheduledTaskFailed when alerts.enabled and cooldown allows', function (): void {
    EventDispatcher::fake([DomainScheduledTaskFailed::class]);

    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 1;

    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    EventDispatcher::assertDispatched(DomainScheduledTaskFailed::class);
});

it('parent Finished is a no-op for background tasks so the child can record real metrics', function (): void {
    // Laravel dispatches `Finished` in the PARENT immediately after
    // spawning a background child (with runtime ≈ 0 and exitCode unset).
    // The real completion data arrives later from `schedule:finish` in
    // the child via `ScheduledBackgroundTaskFinished`. Verify parent's
    // Finished listener leaves the running pointer in place so the
    // child's listener can write the truth.
    $task = makeMutexEvent();
    $task->runInBackground = true;
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);

    // Parent fires Finished synchronously (background or not, Laravel
    // dispatches it after `$event->run()` returns). With runInBackground
    // true our listener should skip everything except the context pop.
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.0));

    expect(R::int('exists', "qmtest:sched:running:{$taskKey}"))->toBe(1);

    // Now the child's listener fires with the real exit code and writes
    // the canonical run record.
    $task->exitCode = 0;
    (new RecordScheduledBackgroundTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledBackgroundTaskFinished($task));

    expect(R::int('exists', "qmtest:sched:running:{$taskKey}"))->toBe(0)
        ->and(R::int('hget', "qmtest:sched:counters:{$taskKey}", 'total_runs'))->toBe(1);
});

it('Finished + Failed dual-fire does not double-count or duplicate the run', function (): void {
    // Laravel dispatches BOTH events for a foreground command/closure
    // failure: `Finished` first (writes the run + deletes the running
    // pointer), then `Failed` from the catch block. Verify the Failed
    // listener enriches the existing run with exception data instead
    // of synthesizing a second run row.
    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);
    $runIdBefore = R::str('hget', "qmtest:sched:running:{$taskKey}", 'run_id');
    expect($runIdBefore)->toBeString();

    $task->exitCode = 1;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    expect(R::int('zcard', "qmtest:sched:runs:{$taskKey}"))->toBe(1)
        ->and(R::int('hget', "qmtest:sched:counters:{$taskKey}", 'total_failed'))->toBe(1);

    $exceptionJson = R::str('hget', "qmtest:sched:run:{$taskKey}:{$runIdBefore}", 'exception');
    expect($exceptionJson)->toContain('boom');
});

it('skips ScheduledTaskFailed dispatch when alerts disabled', function (): void {
    config()->set('queue-insights.scheduler.alerts.enabled', false);
    EventDispatcher::fake([DomainScheduledTaskFailed::class]);

    $task = makeMutexEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 1;

    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    EventDispatcher::assertNotDispatched(DomainScheduledTaskFailed::class);
});

it('sweep command runs both reconcilers and prints a summary', function (): void {
    config()->set('queue-insights.scheduler.heartbeat.enabled', false);

    $schedule = resolveSchedule();
    $schedule->command('demo:run')->everyMinute();

    // Force the sweeper to find one missed fire by lying about last_swept.
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) (Date::now()->getTimestampMs() - 600_000));

    $this->artisan('queue-insights:schedule:sweep')
        ->expectsOutputToContain('Schedule sweep')
        ->assertSuccessful();
});
