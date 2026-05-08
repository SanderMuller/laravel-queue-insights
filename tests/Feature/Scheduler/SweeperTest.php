<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventDispatcher;
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
use SanderMuller\QueueInsights\Scheduler\SchedulerCooldown;
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
    assert($mutex instanceof EventMutex);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = $expression;

    return $event;
}

function resolveSchedule(): Schedule
{
    $schedule = resolve(Schedule::class);
    assert($schedule instanceof Schedule);

    return $schedule;
}

it('flags an expected fire as missed when no Starting was recorded', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    $schedule = resolveSchedule();
    $schedule->command('demo:run')->everyMinute();

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), new SchedulerCooldown());
    $missed = $reconciler->reconcile($schedule);

    expect($missed)->toBeGreaterThanOrEqual(1);
    EventDispatcher::assertDispatched(ScheduledTaskMissed::class);
});

it('does not flag missed when a Starting was within the drift window', function (): void {
    EventDispatcher::fake([ScheduledTaskMissed::class]);

    config()->set('queue-insights.scheduler.sweeper.enabled', false);

    $schedule = resolveSchedule();
    // Use the live Schedule's Event for both the reconciler iteration and
    // the Starting record so the TaskKey matches across both surfaces.
    $event = $schedule->command('demo:run')->everyMinute();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($event));

    // Drop every other registered schedule event so the reconciler's only
    // candidate is our demo:run. (Phase 1 finding: schedule auto-registers
    // queue-insights:snapshot at boot and the test container's
    // beforeEach can't undo that.)
    $only = collect($schedule->events())->filter(
        fn (ScheduleEvent $e): bool => TaskKey::for($e) === TaskKey::for($event),
    )->values();
    $reflection = new ReflectionProperty($schedule, 'events');
    $reflection->setValue($schedule, $only->all());

    $now = Date::now()->getTimestampMs();
    R::raw('setex', 'qmtest:sched:sweeper:last_swept_ms', 3600, (string) ($now - 30_000));

    $reconciler = new MissedRunReconciler(new RunStore(), new ScheduleReader(), new SchedulerCooldown());
    $reconciler->reconcile($schedule, $now);

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

    $reconciler = new HungTaskReconciler(new RunStore(), new ScheduleReader(), new SchedulerCooldown());
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
    $reconciler = new HungTaskReconciler(new RunStore(), new ScheduleReader(), new SchedulerCooldown());

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

    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), new SchedulerCooldown()))
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
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), new SchedulerCooldown()))
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

    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), new SchedulerCooldown()))
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
