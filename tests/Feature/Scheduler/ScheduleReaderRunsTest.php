<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
});

function buildScheduleEvent(string $command = 'php artisan demo:run'): ScheduleEvent
{
    $mutex = resolve(CacheEventMutex::class);
    assert($mutex instanceof EventMutex);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = '* * * * *';

    return $event;
}

it('returns recent runs in newest-first order with row projection', function (): void {
    $task = buildScheduleEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.5));

    $reader = new ScheduleReader();
    $rows = $reader->recentRuns();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['task_key'])->toBe(TaskKey::for($task))
        ->and($rows[0]['status'])->toBe('success')
        ->and($reader->countRuns())
        ->toBe(1);
});

it('omits output / exception / skip_reason / is_background on the list path', function (): void {
    $task = buildScheduleEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.5));

    // Stamp the four large/unused fields directly on the per-run hash so we
    // can verify the list path drops them regardless of what's stored. The
    // modal hydration path (`runDetail` / `runOutput`) is the documented
    // contract for fetching them.
    $reader = new ScheduleReader();
    $rows = $reader->recentRuns();
    $runId = $rows[0]['run_id'];
    $taskKey = $rows[0]['task_key'];
    $runHashKey = 'qmtest:sched:run:' . $taskKey . ':' . $runId;
    $r = Redis::connection('default');
    $r->command('hset', [$runHashKey, 'output', "captured stdout\n"]);
    $r->command('hset', [$runHashKey, 'exception', '{"class":"RuntimeException","message":"boom"}']);
    $r->command('hset', [$runHashKey, 'skip_reason', 'maintenance mode']);
    $r->command('hset', [$runHashKey, 'is_background', '1']);

    $rows = $reader->recentRuns();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['output'])->toBeNull()
        ->and($rows[0]['exception'])->toBeNull()
        ->and($rows[0]['skip_reason'])->toBeNull()
        ->and($rows[0]['is_background'])->toBeFalse();

    // runDetail (the modal hydrator) still surfaces the full payload.
    $detail = $reader->runDetail($taskKey, $runId);
    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['has_output'])->toBeTrue()
        ->and($detail['exception'])->toBeArray()
        ->and($reader->runOutput($taskKey, $runId))->toBe("captured stdout\n");
});

it('filters by status', function (): void {
    $task = buildScheduleEvent();

    // success run
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    // failure run
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    $reader = new ScheduleReader();
    expect($reader->countRuns(['status' => 'failed']))->toBe(1)
        ->and($reader->countRuns(['status' => 'success']))->toBe(1);
});

it('aggregates headline stats across recent runs', function (): void {
    $task = buildScheduleEvent();

    $schedule = $this->app->make(Schedule::class);
    $schedule->command('demo:run')->everyMinute();
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 1.0));

    $stats = (new ScheduleReader())->headlineStats();
    expect($stats['runs_24h'])->toBeGreaterThanOrEqual(1)
        ->and($stats['failed_24h'])->toBe(0);
});

it('produces a 24-bar throughput sparkline shape', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->command('demo:run')->everyMinute();
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    $bars = (new ScheduleReader())->throughputSparkline();
    expect($bars)->toHaveCount(24)
        ->and($bars[0])->toHaveKeys(['hour', 'success', 'failed']);
});

it('returns distinct hosts seen in recent runs', function (): void {
    $task = buildScheduleEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));

    expect((new ScheduleReader())->distinctHosts())
        ->not->toBeEmpty();
});
