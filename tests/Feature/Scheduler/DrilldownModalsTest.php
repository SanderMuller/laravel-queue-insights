<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Http\Livewire\ScheduleInsightsPanel;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
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

function buildSchedulerEventForModalTest(string $command = 'php artisan demo:modal'): ScheduleEvent
{
    $mutex = resolve(CacheEventMutex::class);
    $event = new ScheduleEvent($mutex, $command);
    $event->expression = '* * * * *';

    return $event;
}

function firstRunIdFromZsetForModal(string $key): string
{
    $members = R::raw('zrange', $key, 0, -1);
    if (! is_array($members) || $members === []) {
        return '';
    }

    $first = $members[0];

    return is_string($first) ? $first : '';
}

it('openTaskModal sets the URL slot', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSet('selectedTaskKey', '')
        ->call('openTaskModal', 'abc123')
        ->assertSet('selectedTaskKey', 'abc123');
});

it('closeTaskModal clears the URL slot', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', 'abc123')
        ->call('closeTaskModal')
        ->assertSet('selectedTaskKey', '');
});

it('openRunModal stores composite {taskKey}:{runId} in the slot', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openRunModal', 'task-abc', 'run-001')
        ->assertSet('selectedRunId', 'task-abc:run-001');
});

it('a deep-linked run id round-trips when the run still exists', function (): void {
    $task = buildSchedulerEventForModalTest();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.5));

    $taskKey = TaskKey::for($task);
    $runId = firstRunIdFromZsetForModal("qmtest:sched:runs:{$taskKey}");
    $composite = "{$taskKey}:{$runId}";

    Livewire::withoutLazyLoading();

    // Mount with the composite already in the URL → slot survives.
    Livewire::test(ScheduleInsightsPanel::class, ['selectedRunId' => $composite])
        ->assertSet('selectedRunId', $composite);
});

it('a deep-linked aged-out run id is cleared silently', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class, ['selectedRunId' => 'no-such-task:no-such-run'])
        ->assertSet('selectedRunId', '');
});

it('a malformed run-slot value is also cleared silently', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class, ['selectedRunId' => 'no-colon-no-good'])
        ->assertSet('selectedRunId', '');
});

it('renders the closure capture hint for a closure task', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->call(fn (): null => null)->daily()->name('demo-closure');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    // Find the closure task key from the snapshot.
    $taskKey = null;
    $orderRaw = R::raw('lrange', 'qmtest:sched:tasks:order', 0, -1);
    if (is_array($orderRaw)) {
        foreach ($orderRaw as $key) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            $json = R::str('hget', 'qmtest:sched:tasks', $key);
            if (! is_string($json)) {
                continue;
            }

            $decoded = json_decode($json, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === 'closure') {
                $taskKey = $key;

                break;
            }
        }
    }

    expect($taskKey)->not->toBeNull();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', $taskKey)
        ->assertSee('Output capture not supported by Laravel for closure tasks')
        ->html();

    expect($html)->toContain('&gt; Closure task — Laravel cannot capture stdout for closures');
});

it('does not render the host-distribution panel for a single-host task', function (): void {
    $task = buildSchedulerEventForModalTest();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->assertDontSee('Host distribution');
});

it('renders the host-distribution panel when multiple hosts have run the task', function (): void {
    $taskKey = 'fixturetask';
    $allKey = KeyPrefix::make('sched:runs:all');
    $runsKey = KeyPrefix::make("sched:runs:{$taskKey}");
    foreach (['web-01', 'web-01', 'web-02'] as $i => $host) {
        $runId = 'run-' . $i;
        $runHash = KeyPrefix::make("sched:run:{$taskKey}:{$runId}");
        $now = 1700000000000 + $i;
        R::raw('hset', $runHash, 'started_at', (string) $now);
        R::raw('hset', $runHash, 'status', 'success');
        R::raw('hset', $runHash, 'host_id', $host);
        R::raw('zadd', $runsKey, $now, $runId);
        R::raw('zadd', $allKey, $now, "{$taskKey}:{$runId}");
    }

    // Snapshot row so the per-task modal can find the task in tasksWithStats.
    R::raw('rpush', 'qmtest:sched:tasks:order', $taskKey);
    $snapshotJson = (string) json_encode([
        'description' => 'Demo Task',
        'command' => 'php artisan demo:multi-host',
        'expression' => '* * * * *',
        'timezone' => null,
        'runInBackground' => false,
        'onOneServer' => true,
        'evenInMaintenanceMode' => false,
        'withoutOverlapping' => false,
        'mutexName' => 'mutex',
        'type' => 'command',
    ]);
    R::raw('hset', 'qmtest:sched:tasks', $taskKey, $snapshotJson);

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', $taskKey)
        ->assertSee('Host distribution')
        ->assertSee('web-01')
        ->assertSee('web-02');
});

it('the task modal exports its details as Markdown', function (): void {
    // Registered on the real Schedule so the snapshot carries a row for
    // this task key — the modal only renders for a snapshotted task.
    $task = resolve(Schedule::class)->exec('php artisan demo:markdown')->everyMinute();
    $task->onOneServer = true;
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.25));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->assertSee('Copy task details as Markdown')
        ->assertSeeHtml('id="qi-schedule-task-markdown"')
        ->html();

    expect($html)
        ->toContain('# Scheduled task: php artisan demo:markdown')
        ->toContain('**Cron:** `* * * * *`')
        ->toContain('**Type:** command')
        ->toContain('## Past 24h')
        ->toContain('## Recent runs');
});

it('the task-modal Markdown export carries the needs-attention detail', function (): void {
    $task = resolve(Schedule::class)->exec('php artisan demo:attention')->everyMinute();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('markdown export boom')));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->html();

    expect($html)
        ->toContain('## Needs attention')
        ->toContain('**1 run failed** in the past 24h')
        ->toContain('- **Failing streak:** 1 in a row');
});

it('the task-modal Markdown export omits the needs-attention section for a healthy task', function (): void {
    $task = resolve(Schedule::class)->exec('php artisan demo:healthy')->everyMinute();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->html();

    expect($html)
        ->toContain('# Scheduled task: php artisan demo:healthy')
        ->not->toContain('## Needs attention');
});

it('the task-modal Markdown export neutralises pipes and newlines in free text', function (): void {
    // A shell pipe in an `exec` command is ordinary, and an unescaped `|`
    // splits a Markdown table cell.
    $task = resolve(Schedule::class)->exec("php artisan demo:pipe | grep x\nsecond line")->everyMinute();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->html();

    expect($html)->toContain('# Scheduled task: php artisan demo:pipe \\| grep x second line');
});

it('the task-modal Markdown export widens the code fence around a backticked command', function (): void {
    // Named so the description differs from the command — the Command bullet
    // (the code span under test) is suppressed when the two are identical.
    $task = resolve(Schedule::class)->exec('php artisan demo:tick `hostname`')->everyMinute()->name('Tick host');
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.1));

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', TaskKey::for($task))
        ->html();

    // Two backticks around content whose longest inner run is one, so the span
    // survives instead of closing at the first inner backtick. The symmetric
    // padding is what lets CommonMark strip it back off when rendering.
    expect($html)->toContain('`` php artisan demo:tick `hostname` ``');
});

it('the task-modal Markdown export handles a task with no recent runs', function (): void {
    // Snapshotted but never run — the export must still render, with the
    // recent-runs section falling back to its empty state.
    resolve(Schedule::class)->exec('php artisan demo:never-run')->everyMinute();
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    $taskKey = R::raw('lrange', 'qmtest:sched:tasks:order', 0, -1);
    expect($taskKey)->toBeArray()->not->toBeEmpty();

    Livewire::withoutLazyLoading();

    $html = Livewire::test(ScheduleInsightsPanel::class)
        ->call('openTaskModal', $taskKey[0])
        ->html();

    expect($html)
        ->toContain('## Recent runs')
        ->toContain('No runs in the recent window.')
        ->toContain('- **Last run:** —');
});

it('openJobByUuid dispatches the cross-component event and closes the run modal', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openRunModal', 'task-x', 'run-x')
        ->assertSet('selectedRunId', 'task-x:run-x')
        ->call('openJobByUuid', '00000000-deadbeef')
        ->assertSet('selectedRunId', '')
        ->assertDispatched('qi-open-job-by-uuid', uuid: '00000000-deadbeef');
});

it('openJobByUuid is a no-op for an empty uuid', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openJobByUuid', '')
        ->assertNotDispatched('qi-open-job-by-uuid');
});

it('renders the run modal expired empty state when the deep-link payload is gone', function (): void {
    Livewire::withoutLazyLoading();

    // Open a run modal whose hash never existed — the slot stays set
    // (so the modal renders its empty state) and the operator closes
    // via the X / Esc. mount()-time validation only fires on URL-driven
    // entries, not on runtime opens.
    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openRunModal', 'aged-task', 'aged-run')
        ->assertSet('selectedRunId', 'aged-task:aged-run')
        ->assertSee('This run is no longer available');
});

it('renders the failed-run exception block in the run modal', function (): void {
    $task = buildSchedulerEventForModalTest();
    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    (new RecordScheduledTaskFailed(new RunStore(), new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('explicit boom')));

    $taskKey = TaskKey::for($task);
    $runId = firstRunIdFromZsetForModal("qmtest:sched:runs:{$taskKey}");

    Livewire::withoutLazyLoading();

    // Exception class + message render in the visible block; trace_tail
    // (the actual listener-side key) renders inside the trace `<pre>`.
    // Asserting on a trace fragment locks the listener↔modal contract
    // so future renames stay in lockstep.
    Livewire::test(ScheduleInsightsPanel::class)
        ->call('openRunModal', $taskKey, $runId)
        ->assertSee('explicit boom')
        ->assertSee(RuntimeException::class)
        ->assertSee('DrilldownModalsTest.php');
});
