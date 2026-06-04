<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Scheduler\AggregatesQuery;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
});

/**
 * @param 'success'|'failed'|'hung'|'skipped'|'missed' $status
 */
function recordRun(RunStore $store, string $taskKey, string $status, int $finishedAtMs): void
{
    $store->recordFinish([
        'task_key' => $taskKey,
        'run_id' => "run-{$finishedAtMs}",
        'finished_at_ms' => $finishedAtMs,
        'runtime_ms' => 100,
        'exit_code' => $status === 'failed' ? 1 : 0,
        'status' => $status,
        'output' => null,
        'exception' => $status === 'failed' ? ['class' => 'RuntimeException', 'message' => 'boom'] : null,
    ]);
}

it('increments consecutive_failures on each failed run', function (): void {
    $store = new RunStore();
    $task = 'task-a';

    recordRun($store, $task, 'failed', 1_000);
    recordRun($store, $task, 'failed', 2_000);
    recordRun($store, $task, 'failed', 3_000);

    $counters = (new ScheduleReader())->counters($task);

    expect($counters['consecutive_failures'])->toBe(3)
        ->and($counters['total_failed'])->toBe(3)
        ->and($counters['last_failed_at'])->toBe(3_000)
        ->and($counters['last_success_at'])->toBeNull();
});

it('resets consecutive_failures to 0 on the next success and stamps last_success_at', function (): void {
    $store = new RunStore();
    $task = 'task-b';

    recordRun($store, $task, 'failed', 1_000);
    recordRun($store, $task, 'failed', 2_000);
    recordRun($store, $task, 'success', 3_000);

    $counters = (new ScheduleReader())->counters($task);

    expect($counters['consecutive_failures'])->toBe(0)
        ->and($counters['total_failed'])->toBe(2)
        ->and($counters['last_success_at'])->toBe(3_000);
});

it('rebuilds the streak after a success then more failures', function (): void {
    $store = new RunStore();
    $task = 'task-c';

    recordRun($store, $task, 'failed', 1_000);
    recordRun($store, $task, 'success', 2_000);
    recordRun($store, $task, 'failed', 3_000);
    recordRun($store, $task, 'failed', 4_000);

    expect((new ScheduleReader())->counters($task)['consecutive_failures'])->toBe(2);
});

it('leaves consecutive_failures untouched for skipped/missed runs', function (): void {
    $store = new RunStore();
    $task = 'task-d';

    recordRun($store, $task, 'failed', 1_000);
    recordRun($store, $task, 'skipped', 2_000);

    // skipped is neither a clean success nor a failure of the task body —
    // the streak holds at 1 (it does not reset, nor climb).
    expect((new ScheduleReader())->counters($task)['consecutive_failures'])->toBe(1);
});

it('surfaces consecutive_failures and last_success/last_failed in taskWindowStats', function (): void {
    $store = new RunStore();
    $task = 'task-e';

    recordRun($store, $task, 'success', 1_000);
    recordRun($store, $task, 'failed', 2_000);
    recordRun($store, $task, 'failed', 3_000);

    $stats = (new AggregatesQuery())->taskWindowStats($task);

    expect($stats['consecutive_failures'])->toBe(2)
        ->and($stats['last_success_at_ms'])->toBe(1_000)
        ->and($stats['last_failed_at_ms'])->toBe(3_000);
});
