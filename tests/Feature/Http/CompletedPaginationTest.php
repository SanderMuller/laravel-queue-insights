<?php

declare(strict_types=1);

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
    // window, capping the user at page 2. Bumped to RECENT_FETCH_LIMIT
    // (PER_PAGE * 10) so deep-page bookmarks reach real history.
    $count = DashboardData::RECENT_FETCH_LIMIT;
    seedRecentCompleted($count);

    $component = Livewire::test(QueueInsightsDashboard::class);

    expect($component->viewData('completedTotal'))->toBe($count)
        ->and($component->viewData('completedTotalPages'))->toBe(10);
});
