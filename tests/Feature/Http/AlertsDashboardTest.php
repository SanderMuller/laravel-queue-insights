<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Http\Livewire\AlertRulesPanel;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    // Mute the rules that would fire spurious issues; this file tests the
    // dashboard surface, not the detectors.
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.alerts.rules.snapshot_errored.enabled', false);
    config()->set('queue-insights.alerts.rules.failure_rate.enabled', false);
});

it('hides the alerts strip when no issues are active', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '0');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSeeHtml('aria-label="Active alerts"');
});

it('renders the alerts strip when an issue is active', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '5000');

    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000, 'severity' => 'critical'],
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('aria-label="Active alerts"')
        ->assertSee('Queue depth exceeded')
        ->assertSee('queue: sqsq:work');
});

it('shows the snapshot-watchdog banner when no live:depth keys exist for any configured queue', function (): void {
    // Deliberately do NOT seed live:depth — watchdog should fire.
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Snapshot command appears dead.');
});

it('hides the snapshot-watchdog banner when at least one live:depth key exists', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '0');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSee('Snapshot command appears dead.');
});

it('renders the alert-rules panel with current config', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '0');

    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 4_000, 'severity' => 'warning'],
    ]);
    config()->set('queue-insights.alerts.channels.log.level', 'error');

    // The panel is a `#[Lazy]` child — disable lazy in this test so the
    // initial render returns full HTML, not the placeholder.
    Livewire::withoutLazyLoading();

    Livewire::test(AlertRulesPanel::class)
        ->assertSee('Alert rules')
        ->assertSee('cooldown: 900s')
        ->assertSee('sqsq:work≥4000 (warning)')
        ->assertSee('level: error');
});

it('flags the legacy alerts.thresholds banner inside the rules panel', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '0');

    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1_000],
    ]);

    Livewire::withoutLazyLoading();

    Livewire::test(AlertRulesPanel::class)
        ->assertSee('Legacy');
});

it('ActiveIssuesProvider serves the second call from the 5s Redis cache', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '5000');

    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000, 'severity' => 'critical'],
    ]);

    $provider = resolve(ActiveIssuesProvider::class);

    $first = $provider->get();
    expect($first)->toHaveCount(1)
        ->and($first[0]->rule)->toBe('depth');

    // Cache key must exist and be live (TTL <= 5).
    expect(R::int('exists', 'qmtest:alert:cache:active-issues'))->toBe(1)
        ->and(R::int('ttl', 'qmtest:alert:cache:active-issues'))->toBeLessThanOrEqual(5)
        ->toBeGreaterThanOrEqual(1);

    // Drop the underlying Redis state — if the second call hit the cache,
    // it still returns the issue. If it bypassed the cache, it would return
    // empty.
    Redis::connection('default')->command('del', [KeyPrefix::make('live:depth:sqsq:work')]);
    $provider->flushMemoised();

    $second = $provider->get();
    expect($second)->toHaveCount(1)
        ->and($second[0]->rule)->toBe('depth');
});

it('ActiveIssuesProvider per-request memoise avoids a second Redis read inside one tick', function (): void {
    R::raw('setex', 'qmtest:live:depth:sqsq:work', 90, '5000');

    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000, 'severity' => 'critical'],
    ]);

    $provider = resolve(ActiveIssuesProvider::class);

    $first = $provider->get();
    // Tear down BOTH the live key AND the Redis cache so only the in-memory
    // memoise can save us.
    Redis::connection('default')->command('del', [KeyPrefix::make('live:depth:sqsq:work')]);
    Redis::connection('default')->command('del', [KeyPrefix::make('alert:cache:active-issues')]);

    $second = $provider->get();

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($second[0]->rule)->toBe('depth');
});
