<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\ScheduleInsightsPanel;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.scheduler.enabled', true);
});

it('renders the empty-state when no tasks have been captured', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSee('Tasks')
        ->assertSee('No scheduled tasks captured');
});

it('renders the disabled-state when scheduler.enabled is false', function (): void {
    config()->set('queue-insights.scheduler.enabled', false);

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSee('Scheduler observability is disabled');
});

it('passes when viewScheduleInsights gate allows', function (): void {
    Gate::define('viewScheduleInsights', fn (mixed $user = null): bool => true);

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSee('Tasks');
});

it('renders captured runs in the recent-runs table', function (): void {
    $mutex = resolve(CacheEventMutex::class);
    assert($mutex instanceof EventMutex);
    $task = new ScheduleEvent($mutex, 'php artisan demo:run');
    $task->expression = '* * * * *';

    (new RecordScheduledTaskStarting(new RunStore()))->handle(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    (new RecordScheduledTaskFinished(new RunStore(), new OutputCapturer()))
        ->handle(new ScheduledTaskFinished($task, runtime: 0.25));

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSee('Recent runs')
        ->assertSee('✓ ok');
});

it('clearFilters resets every #[Url] field', function (): void {
    Livewire::test(ScheduleInsightsPanel::class, [])
        ->set('taskFilter', 'foo')
        ->set('statusFilter', 'failed')
        ->set('hostFilter', 'web-01')
        ->call('clearFilters')
        ->assertSet('taskFilter', '')
        ->assertSet('statusFilter', '')
        ->assertSet('hostFilter', '')
        ->assertSet('page', 1);
});
