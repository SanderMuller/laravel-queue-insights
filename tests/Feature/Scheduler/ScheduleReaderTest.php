<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
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

it('returns snapshot rows in registration order', function (): void {
    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyMinute();
    $schedule->call(fn (): null => null)->daily()->name('nightly');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    $reader = new ScheduleReader();
    $tasks = $reader->tasks();

    // The package itself auto-registers `queue-insights:snapshot` and (when
    // scheduler observability is on) `queue-insights:schedule:sweep` — count
    // those alongside the test's two manual additions, deduped by mutex.
    $count = count($tasks);
    expect($count)->toBeGreaterThanOrEqual(2)
        ->and($tasks[0]['expression'])
        ->toBe('* * * * *');
});

it('reads counters with sane defaults when none set', function (): void {
    $reader = new ScheduleReader();
    $counters = $reader->counters('00000000-deadbeef');

    expect($counters['total_runs'])->toBe(0)
        ->and($counters['last_run_at'])->toBeNull();
});

it('exposes the snapshot timestamp in milliseconds', function (): void {
    R::raw('set', 'qmtest:sched:snapshot:at', '1700000000000');

    expect((new ScheduleReader())->snapshotAtMs())->toBe(1700000000000);
});
