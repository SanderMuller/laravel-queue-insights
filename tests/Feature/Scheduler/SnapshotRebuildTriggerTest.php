<?php declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use SanderMuller\QueueInsights\Listeners\RebuildScheduleSnapshot;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);

    $schedule = $this->app->make(Schedule::class);
    $schedule->command('queue-insights:snapshot')->everyMinute()->name('snap');
});

function startCommand(string $command): void
{
    (new RebuildScheduleSnapshot())->handle(new CommandStarting(
        $command,
        new ArrayInput([]),
        new NullOutput(),
    ));
}

it('leaves the roster untouched for an unrelated console command', function (string $command): void {
    startCommand($command);

    expect(R::raw('exists', 'qmtest:sched:tasks:order'))->toEqual(0)
        ->and(R::raw('exists', 'qmtest:sched:snapshot:hash'))->toEqual(0);
})->with(['translations:audit', 'migrate', 'queue:work']);

it('rebuilds the roster when a scheduler-relevant command starts', function (string $command): void {
    startCommand($command);

    $order = R::raw('lrange', 'qmtest:sched:tasks:order', 0, -1);
    expect($order)->toBeArray()
        ->and(count(is_array($order) ? $order : []))->toBeGreaterThanOrEqual(1)
        ->and(R::str('get', 'qmtest:sched:snapshot:hash'))->toBeString();
})->with(['schedule:run', 'queue-insights:schedule:list']);

it('skips the rebuild when the host switched it off', function (): void {
    config()->set('queue-insights.scheduler.snapshot_rebuild', false);

    startCommand('schedule:run');

    expect(R::raw('exists', 'qmtest:sched:tasks:order'))->toEqual(0);
});

// Laravel registers CommandStarting listeners of its own, so assert on the
// raw registration list rather than `hasListeners`.
function rebuildListenerRegistered(): bool
{
    $raw = Event::getRawListeners()[CommandStarting::class] ?? [];

    return in_array(RebuildScheduleSnapshot::class, is_array($raw) ? $raw : [], true);
}

it('registers the rebuild listener when scheduler observability is on', function (): void {
    Event::forget(CommandStarting::class);
    config()->set('queue-insights.scheduler.enabled', true);

    (new QueueInsightsServiceProvider(app()))->boot();

    expect(rebuildListenerRegistered())->toBeTrue();
});

it('does not register the rebuild listener when scheduler observability is off', function (): void {
    Event::forget(CommandStarting::class);
    config()->set('queue-insights.scheduler.enabled', false);

    (new QueueInsightsServiceProvider(app()))->boot();

    expect(rebuildListenerRegistered())->toBeFalse();
});

it('honours a host-configured rebuild command list', function (): void {
    config()->set('queue-insights.scheduler.snapshot_rebuild_commands', ['cron:tick']);

    startCommand('schedule:run');
    expect(R::raw('exists', 'qmtest:sched:tasks:order'))->toEqual(0);

    startCommand('cron:tick');
    expect(R::raw('exists', 'qmtest:sched:tasks:order'))->toEqual(1);
});
