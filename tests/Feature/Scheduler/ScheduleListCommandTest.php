<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('warns when scheduler observability is disabled', function (): void {
    config()->set('queue-insights.scheduler.enabled', false);

    $this->artisan('queue-insights:schedule:list')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

it('renders an empty-state hint when no tasks have been snapshotted', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);

    $this->artisan('queue-insights:schedule:list')
        ->expectsOutputToContain('No scheduled tasks captured')
        ->assertSuccessful();
});

it('lists snapshotted tasks with their cron + counter columns', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);

    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyMinute()->name('snap');
    (new ScheduleSnapshotter(resolve(Schedule::class)))->rebuild();

    $this->artisan('queue-insights:schedule:list')
        ->expectsOutputToContain('snap')
        ->assertSuccessful();
});
