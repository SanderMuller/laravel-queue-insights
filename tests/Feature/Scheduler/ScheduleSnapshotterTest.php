<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
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

it('writes the per-task hash, order list, snapshot hash + at on first rebuild', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyFiveMinutes();
    $schedule->call(fn (): null => null)->daily()->name('nightly-cleanup');

    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    $orderRaw = R::raw('lrange', 'qmtest:sched:tasks:order', 0, -1);
    expect($orderRaw)->toBeArray();
    // The provider may auto-register `queue-insights:snapshot` once
    // Schedule resolves (when `schedule.enabled` is on). Assert our
    // two additions land on top of whatever the host's already
    // captured.
    expect(count(is_array($orderRaw) ? $orderRaw : []))->toBeGreaterThanOrEqual(2);

    $hashRaw = R::raw('hkeys', 'qmtest:sched:tasks');
    expect($hashRaw)->toBeArray()
        ->and(count(is_array($hashRaw) ? $hashRaw : []))
        ->toBeGreaterThanOrEqual(2)
        ->and(R::str('get', 'qmtest:sched:snapshot:hash'))
        ->toBeString()
        ->toHaveLength(64)
        ->and(R::str('get', 'qmtest:sched:snapshot:at'))
        ->toBeString()->not->toBeEmpty();
});

it('leaves the tasks alone but refreshes the snapshot time when the schedule is unchanged', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyMinute();

    Date::setTestNow('2026-10-01 12:00:00');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();
    $hash = R::str('get', 'qmtest:sched:snapshot:hash');
    // Marker the full rewrite would delete.
    R::conn()->command('hset', ['qmtest:sched:tasks', 'marker', 'kept']);

    // A month on, same schedule: the panel must not call it stale.
    Date::setTestNow('2026-11-01 12:00:00');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    expect(R::str('get', 'qmtest:sched:snapshot:at'))->toBe((string) Date::now()->getTimestampMs())
        ->and(R::str('get', 'qmtest:sched:snapshot:hash'))->toBe($hash)
        ->and(R::str('hget', 'qmtest:sched:tasks', 'marker'))->toBe('kept');
});

it('rewrites the tasks hash when the schedule changes', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyMinute();
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();
    $firstHash = R::str('get', 'qmtest:sched:snapshot:hash');

    // Mutate the schedule and snapshot again — the hash should change
    // and the order list should reflect the new task count.
    $schedule->call(fn (): null => null)->everyMinute()->name('extra');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();
    $secondHash = R::str('get', 'qmtest:sched:snapshot:hash');

    expect($secondHash)->not->toBe($firstHash);
    $orderRaw = R::raw('lrange', 'qmtest:sched:tasks:order', 0, -1);
    expect($orderRaw)->toBeArray()
        ->and(count(is_array($orderRaw) ? $orderRaw : []))
        ->toBeGreaterThanOrEqual(2);
});
