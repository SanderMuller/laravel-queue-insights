<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
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

it('renders empty states when there is no configured queue or classes', function (): void {
    // Livewire::test() renders the component in isolation (no layout wrapper) — the
    // "Queue Insights" heading lives in queue-insights::layouts.app and is covered by
    // the HTTP smoke test in DashboardRouteTest.
    Livewire::test(QueueInsightsDashboard::class)
        ->assertOk()
        ->assertSee('No queues configured')
        ->assertSee('No processed jobs in the window')
        ->assertSee('No failed jobs recorded');
});

it('shows the "payload capture off" hint when no completed entries exist and capture is off', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Payload capture is off by default');
});

it('renders queue cards with driver badge, depth, and stale badge when no snapshot has run', function (): void {
    config()->set('queue.connections.myconn', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myconn', 'queue' => 'work'],
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('myconn')
        ->assertSee('work')
        ->assertSee('sqs')
        ->assertSee('no snapshot yet')
        ->assertSee('stale');
});

it('renders `—` for in-flight and delayed when the live cache is empty (null driver semantics)', function (): void {
    config()->set('queue.connections.nullq', ['driver' => 'sync']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'nullq', 'queue' => 'default'],
    ]);

    Redis::connection('default')->command('setex', [KeyPrefix::make('live:depth:nullq:default'), 90, '0']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeText('—');
});

it('shows the error badge when snapshot:error is set', function (): void {
    config()->set('queue.connections.err', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'err', 'queue' => 'work'],
    ]);

    Redis::connection('default')->command('setex', [KeyPrefix::make('snapshot:error:err:work'), 600, 'throttled']);

    Livewire::test(QueueInsightsDashboard::class)->assertSee('error');
});

it('hides the Payload column when capture.payloads = off', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'App\\Foo', 'queue' => 'default']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('App\\Foo')
        ->assertDontSeeHtml('<th class="px-3 py-2">Payload</th>');
});

it('shows the Payload column when capture.payloads = metadata', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'App\\Foo', 'queue' => 'default']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('<th class="px-3 py-2">Payload</th>');
});

it('selects a class and filters the recent completed table', function (): void {
    $r = Redis::connection('default');
    $r->command('zadd', [KeyPrefix::make('classes'), Date::now()->getTimestamp(), 'App\\Foo']);
    seedStream($r, KeyPrefix::make('completed:App\\Foo'), ['queue' => 'default']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectClass', 'App\\Foo')
        ->assertSet('selectedClass', 'App\\Foo')
        ->assertSee('Clear filter');
});

it('enforces viewQueueInsights gate if host app defines it', function (): void {
    Gate::define('viewQueueInsights', fn (mixed $user = null): bool => false);

    // Narrow middleware to just the gate check so we can isolate gate behavior
    // without setting up a full auth guard / session provider.
    config()->set('queue-insights.dashboard.middleware', ['web', 'can:viewQueueInsights']);

    Route::setRoutes(new RouteCollection());
    (new QueueInsightsServiceProvider(app()))->boot();

    $response = $this->get('/queue-insights');

    // 403 (gate denies) or 500 (auth context missing) both indicate the gate layer ran.
    // Only the auth/authz denial codes count — 500 would mask an unrelated crash.
    expect($response->status())->toBeIn([401, 403, 302]);
});
