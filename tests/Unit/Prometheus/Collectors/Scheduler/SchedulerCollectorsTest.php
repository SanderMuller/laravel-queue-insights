<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\HungTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\InFlightCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\LastRunTimestampCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\MissedTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\RunsTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\RuntimeSumCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\SnapshotAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\SweeperAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
    config()->set('queue-insights.prometheus.task_filter', [
        'mode' => TaskFilter::MODE_ALLOW_ALL,
        'tasks' => [],
    ]);
    config()->set('queue-insights.prometheus.metrics.scheduler_runs_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_runtime_sum', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_last_run_timestamp', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_hung_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_missed_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_in_flight', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_snapshot_age', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_sweeper_age', true);

    // Seed the task roster used by every collector.
    R::conn()->command('rpush', [KeyPrefix::make('sched:tasks:order'), 'task-a', 'task-b']);
});

it('runs_total derives success as total_runs - total_failed and emits skipped from total_skipped', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_runs', '10']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_failed', '3']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_skipped', '2']);

    $samples = (new RunsTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    // 3 samples per task × 2 tasks (task-b has no fields → all 0).
    expect($samples)->toHaveCount(6);

    $byStatus = [];
    foreach ($samples as $sample) {
        if ($sample->labels['task'] !== 'task-a') {
            continue;
        }

        $byStatus[$sample->labels['status']] = $sample->value;
    }

    expect($byStatus['success'])->toBe(7.0)
        ->and($byStatus['failed'])->toBe(3.0)
        ->and($byStatus['skipped'])->toBe(2.0);
});

it('runtime_sum reads runtime_sum_ms and converts ms to seconds, omits absent', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'runtime_sum_ms', '12500']);

    $samples = (new RuntimeSumCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    // task-b has no field — sample omitted.
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)->toBe(12.5)
        ->and($samples[0]->labels)->toBe(['task' => 'task-a']);
});

it('last_run_timestamp emits per-status samples in seconds, omits absent', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'last_success_at', '1714737600000']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'last_failed_at', '1714741200000']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-b'), 'last_success_at', '1714737600000']);
    // task-b has no last_failed_at.

    $samples = (new LastRunTimestampCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    expect($samples)->toHaveCount(3);

    $aFailedValue = null;
    foreach ($samples as $sample) {
        if ($sample->labels === ['task' => 'task-a', 'status' => 'failed']) {
            $aFailedValue = $sample->value;
            break;
        }
    }

    expect($aFailedValue)->toBe(1714741200.0);
});

it('hung_total emits zero per task when field absent', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_hung', '4']);

    $samples = (new HungTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    expect($samples)->toHaveCount(2);

    $byTask = [];
    foreach ($samples as $s) {
        $byTask[$s->labels['task']] = $s->value;
    }

    expect($byTask['task-a'])->toBe(4.0)
        ->and($byTask['task-b'])->toBe(0.0);
});

it('missed_total emits zero per task when field absent', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-b'), 'total_missed', '7']);

    $samples = (new MissedTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    $byTask = [];
    foreach ($samples as $s) {
        $byTask[$s->labels['task']] = $s->value;
    }

    expect($byTask['task-b'])->toBe(7.0)
        ->and($byTask['task-a'])->toBe(0.0);
});

it('in_flight emits 1 only for tasks present in running-index zset', function (): void {
    R::conn()->command('zadd', [KeyPrefix::make('sched:running-index'), 9999, 'task-a']);

    $samples = (new InFlightCollector(new TaskFilter()))->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->labels)->toBe(['task' => 'task-a'])
        ->and($samples[0]->value)->toBe(1.0);
});

it('snapshot_age computes seconds from sched:snapshot:at and omits when absent', function (): void {
    Date::setTestNow('2026-05-09 12:00:00');
    $nowMs = Date::now()->getTimestampMs();

    // 30 seconds old.
    R::conn()->command('set', [KeyPrefix::make('sched:snapshot:at'), (string) ($nowMs - 30_000)]);

    $samples = (new SnapshotAgeCollector())->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)->toBe(30.0);

    R::conn()->command('del', [KeyPrefix::make('sched:snapshot:at')]);

    $emptySamples = (new SnapshotAgeCollector())->collect()[0]->samples;
    expect($emptySamples)
        ->toBeEmpty();

    Date::setTestNow();
});

it('sweeper_age reads sched:sweeper:last_swept_ms and omits when absent', function (): void {
    Date::setTestNow('2026-05-09 12:00:00');
    $nowMs = Date::now()->getTimestampMs();
    R::conn()->command('set', [KeyPrefix::make('sched:sweeper:last_swept_ms'), (string) ($nowMs - 75_000)]);

    $samples = (new SweeperAgeCollector())->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)->toBe(75.0);

    R::conn()->command('del', [KeyPrefix::make('sched:sweeper:last_swept_ms')]);
    expect((new SweeperAgeCollector())->collect()[0]->samples)
        ->toBeEmpty();

    Date::setTestNow();
});

it('every collector returns disabled when scheduler.enabled is false', function (): void {
    config()->set('queue-insights.scheduler.enabled', false);

    expect((new RunsTotalCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse()
        ->and((new RuntimeSumCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse()
        ->and((new LastRunTimestampCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse()
        ->and((new HungTotalCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse()
        ->and((new MissedTotalCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse()
        ->and((new InFlightCollector(new TaskFilter()))->isEnabled())->toBeFalse()
        ->and((new SnapshotAgeCollector())->isEnabled())->toBeFalse()
        ->and((new SweeperAgeCollector())->isEnabled())->toBeFalse();
});

it('every collector returns disabled when its specific toggle is false even if scheduler.enabled is true', function (): void {
    config()->set('queue-insights.prometheus.metrics.scheduler_runs_total', false);
    expect((new RunsTotalCollector(new TaskFilter(), new CountersReader()))->isEnabled())->toBeFalse();
    config()->set('queue-insights.prometheus.metrics.scheduler_runs_total', true);

    config()->set('queue-insights.prometheus.metrics.scheduler_in_flight', false);

    expect((new InFlightCollector(new TaskFilter()))->isEnabled())->toBeFalse();
});

it('task_filter allow_list with empty list yields zero samples across collectors', function (): void {
    config()->set('queue-insights.prometheus.task_filter.mode', TaskFilter::MODE_ALLOW_LIST);
    config()->set('queue-insights.prometheus.task_filter.tasks', []);

    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_runs', '10']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_hung', '4']);
    R::conn()->command('zadd', [KeyPrefix::make('sched:running-index'), 9999, 'task-a']);

    expect((new RunsTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples)
        ->toBeEmpty()
        ->and((new HungTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples)
        ->toBeEmpty()
        ->and((new InFlightCollector(new TaskFilter()))->collect()[0]->samples)
        ->toBeEmpty();
});

it('task_filter allow_list emits samples for the configured list as the source of truth (no roster intersection)', function (): void {
    config()->set('queue-insights.prometheus.task_filter.mode', TaskFilter::MODE_ALLOW_LIST);
    // task-c is in allow-list but not in the registered roster — must still emit
    // (operator config is the source of truth; emits a phantom 0-sample for typos
    // rather than silently dropping listed tasks during snapshot rebuild windows).
    config()->set('queue-insights.prometheus.task_filter.tasks', ['task-a', 'task-c']);

    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_hung', '5']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-b'), 'total_hung', '9']);

    $samples = (new HungTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    $byTask = [];
    foreach ($samples as $s) {
        $byTask[$s->labels['task']] = $s->value;
    }

    // task-a present (5), task-c phantom-0, task-b filtered out (not in allow_list).
    expect($byTask)->toBe([
        'task-a' => 5.0,
        'task-c' => 0.0,
    ]);
});

it('task_filter allow_list survives a fully-empty roster (snapshot rebuild window / pre-seeded host)', function (): void {
    config()->set('queue-insights.prometheus.task_filter.mode', TaskFilter::MODE_ALLOW_LIST);
    config()->set('queue-insights.prometheus.task_filter.tasks', ['task-a']);

    // Wipe the roster — simulates a host that disabled snapshot_rebuild and
    // never seeded `sched:tasks:order`, OR a scrape mid-`DEL`+`RPUSH`.
    R::conn()->command('del', [KeyPrefix::make('sched:tasks:order')]);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:task-a'), 'total_hung', '11']);

    $samples = (new HungTotalCollector(new TaskFilter(), new CountersReader()))->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->labels)->toBe(['task' => 'task-a'])
        ->and($samples[0]->value)->toBe(11.0);
});
