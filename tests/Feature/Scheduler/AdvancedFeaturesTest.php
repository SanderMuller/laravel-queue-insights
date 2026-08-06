<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
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
    config()->set('queue-insights.pending.enabled', true);

    ScheduleContext::flush();
});

afterEach(function (): void {
    ScheduleContext::flush();
});

it('disambiguates two unnamed closures via reflection', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    $a = new CallbackEvent($mutex, fn (): null => null);
    $b = new CallbackEvent($mutex, fn (): null => null);

    expect(TaskKey::for($a))->not->toBe(TaskKey::for($b));
});

it('uses mutex name for commands (not reflection)', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    $a = new ScheduleEvent($mutex, 'php artisan demo:run');
    $a->expression = '* * * * *';

    $b = new ScheduleEvent($mutex, 'php artisan demo:run');
    $b->expression = '* * * * *';

    expect(TaskKey::for($a))->toBe(TaskKey::for($b));
});

it('Starting → Finished pushes and pops the schedule context frame', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    $task = new ScheduleEvent($mutex, 'php artisan demo:run');
    $task->expression = '* * * * *';

    expect(ScheduleContext::current())->toBeNull();

    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $current = ScheduleContext::current();
    expect($current)->toBeArray();
    assert(is_array($current));
    expect($current['task_key'])->toBe(TaskKey::for($task));

    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    expect(ScheduleContext::current())->toBeNull();
});

it('attributes a job dispatched inside a schedule run', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    $task = new ScheduleEvent($mutex, 'php artisan demo:run');
    $task->expression = '* * * * *';

    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $taskKey = TaskKey::for($task);
    $runId = (string) R::str('hget', "qmtest:sched:running:{$taskKey}", 'run_id');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FXF';
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\FromSchedule']);

    (new RecordJobQueued())->handle(new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'driver-id-' . Str::random(6),
        job: (object) ['displayName' => 'App\\Jobs\\FromSchedule'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    ));

    expect(R::str('hget', "qmtest:pending:{$uuid}", 'schedule_task_key'))->toBe($taskKey)
        ->and(R::str('hget', "qmtest:pending:{$uuid}", 'schedule_run_id'))->toBe($runId)
        ->and((new ScheduleReader())
            ->jobsDispatchedDuring($runId))
        ->toContain($uuid);
});

it('does not attribute jobs when the schedule context is empty', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FZZ';
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\FromHttp']);

    (new RecordJobQueued())->handle(new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'driver-id-' . Str::random(6),
        job: (object) ['displayName' => 'App\\Jobs\\FromHttp'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    ));

    expect(R::str('hget', "qmtest:pending:{$uuid}", 'schedule_task_key'))->toBeNull();
});

it('hostDistribution counts per host across recent runs', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    $task = new ScheduleEvent($mutex, 'php artisan demo:run');
    $task->expression = '* * * * *';

    $taskKey = TaskKey::for($task);
    R::raw('hset', "qmtest:sched:run:{$taskKey}:r1", 'host_id', 'web-01');
    R::raw('hset', "qmtest:sched:run:{$taskKey}:r2", 'host_id', 'web-01');
    R::raw('hset', "qmtest:sched:run:{$taskKey}:r3", 'host_id', 'web-02');
    R::raw('zadd', "qmtest:sched:runs:{$taskKey}", 1, 'r1');
    R::raw('zadd', "qmtest:sched:runs:{$taskKey}", 2, 'r2');
    R::raw('zadd', "qmtest:sched:runs:{$taskKey}", 3, 'r3');

    $dist = (new ScheduleReader())->hostDistribution($taskKey);
    expect($dist)->toBe(['web-01' => 2, 'web-02' => 1]);
});
