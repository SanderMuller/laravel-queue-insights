<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    Context::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
    config()->set('queue-insights.capture.redact_keys', ['.*password.*', '.*secret.*', '.*token.*', '.*api[_-]?key.*', '.*authorization.*', '.*credential.*']);
    config()->set('queue-insights.failure_context.enabled', true);
    config()->set('queue-insights.failure_context.capture_app_context', true);
    config()->set('queue-insights.failure_context.capture_environment', true);
});

afterEach(fn () => Context::flush());

function makeFailureCtxScheduleEvent(): ScheduleEvent
{
    $event = new ScheduleEvent(resolve(CacheEventMutex::class), 'php artisan demo:run');
    $event->expression = '* * * * *';

    return $event;
}

it('captures the deepest inner exception + context + environment on a failed run', function (): void {
    $task = makeFailureCtxScheduleEvent();
    $taskKey = TaskKey::for($task);
    $runId = (string) Str::ulid();
    $now = Date::now()->getTimestampMs();

    $store = new RunStore();
    $store->recordStarting([
        'task_key' => $taskKey,
        'run_id' => $runId,
        'started_at_ms' => $now,
        'host_id' => 'host-1',
        'is_background' => false,
        'expected_finish_at_ms' => $now + 60_000,
    ]);

    Context::add(['user_id' => 7, 'password' => 'hunter2']);

    // 3-level chain — the reaper must surface the DEEPEST previous.
    $root = new RuntimeException('root cause');
    $mid = new LogicException('mid layer', 0, $root);
    $outer = new RuntimeException('wrapper', 0, $mid);

    (new RecordScheduledTaskFailed($store, new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, $outer));

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);
    if ($detail === null) {
        throw new RuntimeException('expected a run detail for the seeded run');
    }

    $exception = $detail['exception'] ?? [];
    $appContext = $detail['app_context'] ?? [];
    $environment = $detail['environment'] ?? [];

    expect($exception['class'] ?? null)->toBe(RuntimeException::class)
        ->and($exception['inner_class'] ?? null)->toBe(RuntimeException::class)
        ->and($exception['inner_message'] ?? null)->toBe('root cause')
        ->and($appContext['user_id'] ?? null)->toBe(7)
        ->and($appContext['password'] ?? null)->toBe('[REDACTED]')
        ->and($environment['host'] ?? null)->toBeString()
        ->and($environment['env'] ?? null)->toBe('testing');
});

it('omits inner exception fields when there is no previous exception', function (): void {
    $task = makeFailureCtxScheduleEvent();
    $taskKey = TaskKey::for($task);
    $runId = (string) Str::ulid();
    $now = Date::now()->getTimestampMs();

    $store = new RunStore();
    $store->recordStarting([
        'task_key' => $taskKey,
        'run_id' => $runId,
        'started_at_ms' => $now,
        'host_id' => 'host-1',
        'is_background' => false,
        'expected_finish_at_ms' => $now + 60_000,
    ]);

    (new RecordScheduledTaskFailed($store, new OutputCapturer(), resolve(IssueDispatcher::class)))
        ->handle(new ScheduledTaskFailed($task, new RuntimeException('flat')));

    $detail = (new ScheduleReader())->runDetail($taskKey, $runId);
    if ($detail === null) {
        throw new RuntimeException('expected a run detail for the seeded run');
    }

    $exception = $detail['exception'] ?? [];

    expect($exception['inner_class'] ?? null)->toBeNull()
        ->and($exception['class'] ?? null)->toBe(RuntimeException::class);
});
