<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
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

function seedScheduleRuns(int $count): void
{
    $mutex = resolve(CacheEventMutex::class);
    $store = new RunStore();
    $capturer = new OutputCapturer();
    $starting = new RecordScheduledTaskStarting($store);
    $finished = new RecordScheduledTaskFinished($store, $capturer);

    for ($i = 0; $i < $count; ++$i) {
        $task = new ScheduleEvent($mutex, "php artisan demo:run --i={$i}");
        $task->expression = '* * * * *';
        $starting->handle(new ScheduledTaskStarting($task));
        $task->exitCode = 0;
        $finished->handle(new ScheduledTaskFinished($task, runtime: 0.1));
    }
}

it('exposes a LengthAwarePaginator with s_p pageName and per-page scaffolding', function (): void {
    seedScheduleRuns(12);

    Livewire::withoutLazyLoading();
    $component = Livewire::test(ScheduleInsightsPanel::class);

    $paginator = $component->viewData('runsPaginator');
    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(12)
        ->and($paginator->perPage())->toBe(10)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getPageName())->toBe('s_p')
        ->and($component->viewData('perPageOptions'))
        ->toBe(DashboardData::PER_PAGE_OPTIONS);
});

it('gotoRunsPage walks pages and changing perPage resets to page 1', function (): void {
    seedScheduleRuns(60);

    Livewire::withoutLazyLoading();
    $component = Livewire::test(ScheduleInsightsPanel::class)
        ->set('perPage', 10)
        ->call('gotoRunsPage', 3)
        ->assertSet('page', 3);

    $component->set('perPage', 50)
        ->assertSet('perPage', 50)
        ->assertSet('page', 1);

    expect($component->viewData('runsPaginator')->perPage())->toBe(50)
        ->and($component->viewData('runsPaginator')->currentPage())->toBe(1);
});

it('rejects out-of-whitelist perPage values and snaps back to 10', function (): void {
    Livewire::withoutLazyLoading();

    $component = Livewire::test(ScheduleInsightsPanel::class)
        ->set('perPage', 999999);

    expect($component->get('perPage'))->toBe(10);
});

it('clamps URL-hydrated perPage on every request via boot()', function (): void {
    Livewire::withoutLazyLoading();
    Livewire::withQueryParams(['s_pp' => 999999]);

    $component = Livewire::test(ScheduleInsightsPanel::class);

    expect($component->get('perPage'))->toBe(10)
        ->and($component->viewData('runsPaginator')->perPage())->toBe(10);
});

it('clamps an out-of-range page deep-link back to the last available page', function (): void {
    seedScheduleRuns(15);

    Livewire::withoutLazyLoading();
    Livewire::withQueryParams(['s_pp' => 10, 's_p' => 999]);

    $component = Livewire::test(ScheduleInsightsPanel::class);

    // 15 rows / 10 per-page = 2 pages; deep-link to 999 should clamp to 2.
    expect($component->viewData('runsPaginator')->currentPage())->toBe(2);
});
