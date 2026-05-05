<?php declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
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
        ->assertSee('No completed jobs recorded yet')
        ->assertSee('No failed jobs recorded');
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

it('always shows the Details column regardless of capture.payloads', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'App\\Foo', 'queue' => 'default']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('App\\Foo')
        ->assertSee('Details');
});

it('defaults $payloadTab to raw', function (): void {
    // Default is `raw` per Resolved Q #18 (dogfood feedback: KV table is the
    // 80% case for flat job payloads; nested payloads can flip to JSON).
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('payloadTab', 'raw');
});

it('setPayloadTab flips to json on valid input', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('setPayloadTab', 'json')
        ->assertSet('payloadTab', 'json');
});

it('setPayloadTab silently drops invalid input', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('setPayloadTab', 'xml')
        ->assertSet('payloadTab', 'raw');
});

it('openPayload resets payloadTab to raw even if a prior open left it on json', function (): void {
    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'App\\Foo', 'queue' => 'default']);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'] ?? null;
    expect($id)->toBeString();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('setPayloadTab', 'json')
        ->assertSet('payloadTab', 'json')
        ->call('openPayload', $id)
        ->assertSet('payloadTab', 'raw');
});

it('shows the capture mode badge in the details modal header', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'App\\Foo', 'queue' => 'default']);

    // Resolve the real stream _id via QueueInsights, not via component internals —
    // recentCompleted is a per-render local in render(), not a public Livewire property.
    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'] ?? null;
    expect($id)->toBeString();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSee('capture: off')
        ->assertSee('Capture is off');
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

it('Classes tab mounts the per-class table and renders the silenced badge for a silenced class', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);
    app()->forgetScopedInstances();

    $r = Redis::connection('default');
    $now = Date::now();
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\Jobs\\Noisy']);
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\Jobs\\Quiet']);

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    // Tab strip carries the Classes button + both class FQCNs render in the
    // pane, with the silenced badge gated to the silenced class.
    expect($html)->toContain('Classes')
        ->and($html)->toContain('App\\Jobs\\Noisy')
        ->and($html)->toContain('App\\Jobs\\Quiet')
        ->and($html)->toContain('>silenced<');

    // The badge only renders for the silenced class. Confirm by slicing
    // a window around the badge and checking the nearest preceding FQCN
    // is the silenced one.
    $badgePos = strpos($html, '>silenced<');
    expect($badgePos)->toBeInt();
    $window = substr($html, max(0, (int) $badgePos - 500), 500);
    expect($window)->toContain('App\\Jobs\\Noisy')
        ->and($window)->not->toContain('App\\Jobs\\Quiet');
});

it('selectClass with a FQCN populates the filtered completed table', function (): void {
    // Higher-level regression: after the selectClass call lands server-side with the
    // correct FQCN (backslashes intact), the per-class stream read must return rows.
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\Video\\DuplicateInteractionsJob';
    $r->command('zadd', [KeyPrefix::make('classes'), Date::now()->getTimestamp(), $class]);
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['queue' => 'default']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectClass', $class)
        ->assertSet('selectedClass', $class)
        ->assertSee('Clear filter')
        ->assertDontSee('No completed jobs recorded yet');
});

it('Throughput sparkline wires Alpine hover state per bucket with a tooltip overlay', function (): void {
    $r = Redis::connection('default');
    $now = Date::now('UTC');
    $thisHour = $now->format('YmdH');

    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\A']);
    $r->command('set', [KeyPrefix::make("processed:App\\A:{$thisHour}"), '12']);
    $r->command('set', [KeyPrefix::make("failed:App\\A:{$thisHour}"), '3']);

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    // 24 hover-target rects with data-qi-bar index attribute.
    expect(substr_count($html, 'data-qi-bar='))->toBe(24)
        // Alpine x-data with `hovered` state and the `buckets` lookup — the SVG
        // rects' x-on:mouseenter handlers set this state to display the tooltip.
        ->and($html)->toContain('hovered: null')
        ->and($html)->toContain('buckets:')
        // Tooltip overlay markup with x-show binding — present in DOM but hidden
        // initially via x-show + x-cloak.
        ->and($html)->toContain('x-show="hovered !== null"')
        ->and($html)->toContain('x-text="buckets[hovered]?.label"');
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
