<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\EventMutex;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Listeners\RecordScheduledBackgroundTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskSkipped;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
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
    config()->set('queue-insights.scheduler.capture.output', 'metadata');
});

function makeScheduleEvent(string $command = 'php artisan demo:run'): ScheduleEvent
{
    $mutex = resolve(CacheEventMutex::class);
    assert($mutex instanceof EventMutex);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = '* * * * *';

    return $event;
}

it('records starting → run hash + zsets + running pointer', function (): void {
    $task = makeScheduleEvent();
    $listener = new RecordScheduledTaskStarting(new RunStore());

    $listener->handle(new ScheduledTaskStarting($task));

    $taskKey = TaskKey::for($task);
    $running = R::raw('hgetall', "qmtest:sched:running:{$taskKey}");
    expect($running)->toBeArray();
    assert(is_array($running));
    expect($running['run_id'] ?? null)->toBeString();
    $startedAt = is_numeric($running['started_at_ms'] ?? null) ? (int) $running['started_at_ms'] : 0;
    $expectedFinish = is_numeric($running['expected_finish_at_ms'] ?? null) ? (int) $running['expected_finish_at_ms'] : 0;
    expect($expectedFinish)->toBeGreaterThan($startedAt);

    $runId = is_string($running['run_id'] ?? null) ? $running['run_id'] : '';
    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('starting')
        ->and(R::int('zcard', "qmtest:sched:runs:{$taskKey}"))
        ->toBe(1)
        ->and(R::int('zcard', 'qmtest:sched:runs:all'))
        ->toBe(1);
});

it('records finished → updates hash + drops running + bumps counters', function (): void {
    $task = makeScheduleEvent();
    $startingListener = new RecordScheduledTaskStarting(new RunStore());
    $startingListener->handle(new ScheduledTaskStarting($task));

    $taskKey = TaskKey::for($task);
    $runId = R::str('hget', "qmtest:sched:running:{$taskKey}", 'run_id') ?? '';

    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 1.234));

    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('success')
        ->and((int) R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'runtime_ms'))->toBe(1234)
        ->and(R::int('exists', "qmtest:sched:running:{$taskKey}"))->toBe(0)
        ->and(R::int('hget', "qmtest:sched:counters:{$taskKey}", 'total_runs'))->toBe(1);
});

it('records failed → captures exception + flips counter', function (): void {
    $task = makeScheduleEvent();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);

    $task->exitCode = 1;
    $exception = new RuntimeException('boom');

    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, $exception));

    $rangeRaw = R::raw('zrange', "qmtest:sched:runs:{$taskKey}", 0, -1);
    $runId = is_array($rangeRaw) && is_string($rangeRaw[0] ?? null) ? $rangeRaw[0] : '';

    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('failed')
        ->and(R::int('hget', "qmtest:sched:counters:{$taskKey}", 'total_failed'))->toBe(1);

    $exceptionJson = R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'exception');
    expect($exceptionJson)->toContain('boom')->toContain('RuntimeException');
});

it('records skipped → writes a synthetic run + skip reason + counter', function (): void {
    $task = makeScheduleEvent();
    $taskKey = TaskKey::for($task);

    (new RecordScheduledTaskSkipped(new RunStore(), app()))
        ->handle(new ScheduledTaskSkipped($task));

    expect(R::int('hget', "qmtest:sched:counters:{$taskKey}", 'total_skipped'))->toBe(1);

    $members = R::raw('zrange', "qmtest:sched:runs:{$taskKey}", 0, -1);
    expect($members)->toBeArray()->toHaveCount(1);

    $runId = is_array($members) && is_string($members[0] ?? null) ? $members[0] : '';
    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('skipped')
        ->and(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'skip_reason'))
        ->toBeIn(['mutex', 'one_server', 'maintenance', 'between', 'filter']);
});

it('records background-task-finished by reading the running pointer', function (): void {
    $task = makeScheduleEvent();
    $task->runInBackground = true;
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);
    $runId = R::str('hget', "qmtest:sched:running:{$taskKey}", 'run_id') ?? '';

    $task->exitCode = 0;
    (new RecordScheduledBackgroundTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledBackgroundTaskFinished($task));

    expect(R::str('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'status'))->toBe('success')
        ->and(R::int('hget', "qmtest:sched:run:{$taskKey}:{$runId}", 'is_background'))->toBe(1);
});
