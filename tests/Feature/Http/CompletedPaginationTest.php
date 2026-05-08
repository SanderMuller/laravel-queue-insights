<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.capture.payloads', 'off');
    config()->set('queue-insights.snapshots', []);
});

function seedRecentCompleted(int $count): void
{
    $r = Redis::connection('default');
    $globalKey = KeyPrefix::make('completed');

    // Insert oldest-first so XREVRANGE returns newest-first by stream id.
    for ($i = 0; $i < $count; ++$i) {
        seedStream($r, $globalKey, [
            '_id' => sprintf('row-%03d', $i),
            'class' => 'App\\Jobs\\PaginationCanary',
            'queue' => 'default',
            'connection' => 'redis',
            'duration_ms' => '120',
            'attempts' => '1',
            'processed_at' => Date::now()->subSeconds($count - $i)->toIso8601String(),
        ]);
    }
}

it('Overview pane preview rows do not shift when the user paginates the Completed tab', function (): void {
    // Regression for codex review #3: the Overview "Recent completed" card
    // used to read from `array_slice($completedRows, 0, 5)`, but
    // $completedRows is the page-local slice. Navigating to page 2 of
    // Completed and back to Overview surfaced page-2 rows in the card.
    // The fix routes Overview through a dedicated `$completedPreview`
    // built from the unsliced post-filter list.
    seedRecentCompleted(60);

    $component = Livewire::test(QueueInsightsDashboard::class);

    // Capture the Overview preview on page 1 — should be the most-recent rows.
    $previewOnPage1 = $component->viewData('completedPreview');
    expect($previewOnPage1)->toHaveCount(5);

    // Navigate to page 2 of Completed.
    $component->call('gotoCompletedPage', 2)
        ->assertSet('completedPage', 2);

    // Overview preview must still show the same most-recent rows it did on page 1.
    $previewOnPage2 = $component->viewData('completedPreview');

    expect($previewOnPage2)->toBe($previewOnPage1);
});

it('Completed pagination paginates over RECENT_FETCH_LIMIT rows, not just 50', function (): void {
    // Regression for codex review #2: pagination used to slice a 50-row
    // window, capping the user at page 2. Now sliced from
    // `RECENT_FETCH_LIMIT` (250) so deep-page bookmarks reach real history.
    $count = DashboardData::RECENT_FETCH_LIMIT;
    seedRecentCompleted($count);

    $component = Livewire::test(QueueInsightsDashboard::class);

    expect($component->viewData('completedTotal'))->toBe($count)
        ->and($component->viewData('completedTotalPages'))
        ->toBe((int) ceil(DashboardData::RECENT_FETCH_LIMIT / DashboardData::PER_PAGE));
});

it('changing completedPerPage rebuilds the slice and resets to page 1', function (): void {
    seedRecentCompleted(60);

    $component = Livewire::test(QueueInsightsDashboard::class)
        // Walk to page 3 with the default per-page=10 (60 rows = 6 pages).
        ->call('gotoCompletedPage', 3)
        ->assertSet('completedPage', 3);

    // Switch per-page to 50. updated() hook should reset page to 1 and
    // the paginator should report 60 / 50 = 2 pages, current = 1.
    $component->set('completedPerPage', 50)
        ->assertSet('completedPerPage', 50)
        ->assertSet('completedPage', 1);

    expect($component->viewData('completedPerPage'))->toBe(50)
        ->and($component->viewData('completedTotalPages'))->toBe(2)
        ->and($component->viewData('completedPaginator')->perPage())->toBe(50)
        ->and($component->viewData('completedPaginator')->currentPage())->toBe(1)
        ->and($component->viewData('completedPaginator')->total())->toBe(60)
        ->and($component->viewData('completedRows'))->toHaveCount(50);
});

it('rejects out-of-whitelist completedPerPage values and snaps back to the default', function (): void {
    seedRecentCompleted(30);

    // Hostile URL param `?cpp=999999` would otherwise force
    // array_slice($all, 0, 999999). updated() hook clamps to PER_PAGE.
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('completedPerPage', 999999);

    expect($component->get('completedPerPage'))->toBe(DashboardData::PER_PAGE);
});

it('clamps URL-hydrated per-page props on every request via boot()', function (): void {
    // URL-hydration path: `#[Url]` re-reads the query string on every request,
    // bypassing `updated()`. `boot()` is the catch-all that runs before render
    // so the dropdown's wire:model and the paginator's slice stay in sync.
    seedRecentCompleted(30);

    // Simulate URL hydration by setting the prop to a hostile value during
    // mount (Livewire::withQueryParams does the same in newer versions).
    // After mount + boot + render, both the prop and the paginator should
    // report the clamped default value, not the hostile one.
    Livewire::withQueryParams(['cpp' => 999999, 'fpp' => -1]);
    $component = Livewire::test(QueueInsightsDashboard::class);

    expect($component->get('completedPerPage'))->toBe(DashboardData::PER_PAGE)
        ->and($component->get('failedPerPage'))->toBe(DashboardData::PER_PAGE)
        ->and($component->viewData('completedPaginator')->perPage())->toBe(DashboardData::PER_PAGE);
});

it('exposes a LengthAwarePaginator with cp pageName and per-page scaffolding', function (): void {
    seedRecentCompleted(40);

    $component = Livewire::test(QueueInsightsDashboard::class);

    $paginator = $component->viewData('completedPaginator');
    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(40)
        ->and($paginator->perPage())->toBe(DashboardData::PER_PAGE)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getPageName())->toBe('cp')
        ->and($paginator->firstItem())->toBe(1)
        ->and($paginator->lastItem())->toBe(DashboardData::PER_PAGE)
        ->and($paginator->hasMorePages())->toBeTrue()
        ->and($component->viewData('perPageOptions'))
        ->toBe(DashboardData::PER_PAGE_OPTIONS);
});

it('exposes a LengthAwarePaginator for the failed list with fp pageName', function (): void {
    // Failed list reads from the `failed_jobs` DB table — recentFailed()
    // gracefully returns [] when the table is missing in the test env, so
    // we get an empty paginator instance to assert shape without needing
    // a per-test schema bootstrap.
    $component = Livewire::test(QueueInsightsDashboard::class);

    $paginator = $component->viewData('failedPaginator');
    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(0)
        ->and($paginator->perPage())->toBe(DashboardData::PER_PAGE)
        ->and($paginator->getPageName())->toBe('fp');
});

it('rejects out-of-whitelist failedPerPage values (parity with completed)', function (): void {
    // Failed-side parity for the perPage clamp. The reset-on-perPage-change
    // path is structurally identical to the completed-side one (same
    // `updated()` hook, same conditional block) so it doesn't get its own
    // test — the completed-side test exercises that code path. This test
    // covers the perPage *whitelist* enforcement specifically because the
    // failed pane doesn't need a `failed_jobs` schema for the assertion.
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('failedPerPage', 999999);

    expect($component->get('failedPerPage'))->toBe(DashboardData::PER_PAGE);
});

it('snaps the completedPage prop back to the clamped value when the URL is stale', function (): void {
    // Hostile / stale `?cp=99999` on a 60-row list (6 pages at default
    // per-page=10) should render the last available page AND write the
    // prop back so the URL emitted to the browser reflects the rendered
    // state. Computed from the same constant the slicer reads so the
    // assertion stays correct if the default ever changes again.
    seedRecentCompleted(60);
    $expectedPage = (int) ceil(60 / DashboardData::PER_PAGE);

    Livewire::withQueryParams(['cp' => 99999]);
    $component = Livewire::test(QueueInsightsDashboard::class);

    expect($component->get('completedPage'))->toBe($expectedPage)
        ->and($component->viewData('completedPaginator')->currentPage())->toBe($expectedPage);
});

it('renders the per-page select with .number coercion modifier', function (): void {
    seedRecentCompleted(40);

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    // `.number` modifier coerces "50" string from the browser to int 50
    // before Livewire writes onto the typed `int $completedPerPage` prop.
    expect($html)
        ->toContain('wire:model.live.number="completedPerPage"');
});
