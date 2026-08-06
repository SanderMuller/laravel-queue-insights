<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
});

function buildEventForDetail(string $command = 'php artisan demo:detail'): ScheduleEvent
{
    $mutex = resolve(CacheEventMutex::class);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = '* * * * *';

    return $event;
}

function firstRunIdFromZsetForDetail(string $key): string
{
    $members = R::raw('zrange', $key, 0, -1);
    if (! is_array($members) || $members === []) {
        return '';
    }

    $first = $members[0];

    return is_string($first) ? $first : '';
}

it('returns null for an unknown run id', function (): void {
    expect((new ScheduleReader())->runDetail('does-not-exist', '00000000-feedface'))->toBeNull();
});

it('returns null for an empty taskKey or runId', function (): void {
    $reader = new ScheduleReader();
    expect($reader->runDetail('', 'whatever'))->toBeNull()
        ->and($reader->runDetail('whatever', ''))->toBeNull();
});

it('hydrates a finished run with status + runtime + has_output flag', function (): void {
    $task = buildEventForDetail();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.5));

    $taskKey = TaskKey::for($task);
    $runId = firstRunIdFromZsetForDetail("qmtest:sched:runs:{$taskKey}");
    expect($runId)->not->toBeEmpty();

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['task_key'])->toBe($taskKey)
        ->and($detail['run_id'])->toBe($runId)
        ->and($detail['status'])->toBe('success')
        ->and($detail['exit_code'])->toBe(0)
        ->and($detail['runtime_ms'])->toBe(500)
        ->and($detail['has_output'])->toBeFalse()
        ->and($detail['exception'])->toBeNull()
        ->and($detail['correlated_jobs'])->toBeEmpty();
});

it('decodes the exception JSON on a failed run', function (): void {
    $task = buildEventForDetail();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('kaboom')));

    $taskKey = TaskKey::for($task);
    $runId = firstRunIdFromZsetForDetail("qmtest:sched:runs:{$taskKey}");

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['status'])->toBe('failed');

    $exception = $detail['exception'];
    expect($exception)->not->toBeNull();
    if ($exception === null) {
        return;
    }

    expect($exception['class'] ?? null)->toBe(RuntimeException::class)
        ->and($exception['message'] ?? null)->toBe('kaboom');
});

it('exposes runOutput separately from runDetail', function (): void {
    $taskKey = 'fixturetask';
    $runId = '00000000-cafebabe';
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'started_at', '1700000000000');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'status', 'success');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'host_id', 'web-01');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'output', 'hello stdout');

    $reader = new ScheduleReader();
    $detail = $reader->runDetail($taskKey, $runId);

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['has_output'])->toBeTrue()
        ->and($reader->runOutput($taskKey, $runId))->toBe('hello stdout');
});

it('returns null runOutput when no blob was captured', function (): void {
    $taskKey = 'fixturetask';
    $runId = '00000000-deadbeef';
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'started_at', '1700000000000');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'status', 'success');

    expect((new ScheduleReader())->runOutput($taskKey, $runId))->toBeNull();
});

it('exposes recovered_from_hung when a finished run flips a prior hung row', function (): void {
    $taskKey = 'fixturetask';
    $runId = '00000000-recovery';
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'started_at', '1700000000000');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'finished_at', '1700000060000');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'status', 'success');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'recovered_from_hung', '1');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'host_id', 'web-01');

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['recovered_from_hung'])->toBeTrue();
});

it('lists correlated jobs dispatched during the run', function (): void {
    $taskKey = 'fixturetask';
    $runId = '00000000-correlated';
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'started_at', '1700000000000');
    R::raw('hset', KeyPrefix::make("sched:run:{$taskKey}:{$runId}"), 'status', 'success');
    R::raw('zadd', KeyPrefix::make("sched:run-jobs:{$runId}"), 1700000001000, 'uuid-a');
    R::raw('zadd', KeyPrefix::make("sched:run-jobs:{$runId}"), 1700000002000, 'uuid-b');

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['correlated_jobs'])->toBe(['uuid-a', 'uuid-b']);
});
