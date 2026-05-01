<?php declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDetector;
use SanderMuller\QueueInsights\Alerts\SnapshotWatchdog;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

it('registers the connection-scoped route by default', function (): void {
    expect(Route::has('queue-insights.connection'))->toBeTrue();
});

it('aborts 404 in the dashboard mount when {connection} is not in the configured snapshots', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
        ['connection' => 'redis', 'queue' => 'default'],
    ]);

    Gate::define('viewQueueInsights', fn (?User $user = null): bool => true);

    $user = (new User())->forceFill(['id' => 1, 'name' => 'dev', 'email' => 'dev@example.test']);

    test()->actingAs($user)->get('/queue-insights/sqs')->assertOk();
    test()->actingAs($user)->get('/queue-insights/redis')->assertOk();
    test()->actingAs($user)->get('/queue-insights/unknown')->assertNotFound();
});

it('populates scopeConnection on mount when given a configured connection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'sqs'])
        ->assertSet('scopeConnection', 'sqs');
});

it('leaves scopeConnection null when no connection is supplied', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('scopeConnection', null);
});

it('aborts 404 when an embedded mount receives an unconfigured connection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // RequestBroker re-renders the HttpException through the configured handler
    // rather than letting it propagate, so the response surfaces as a 404 on
    // the Testable's underlying TestResponse rather than a thrown exception.
    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'unknown'])
        ->assertNotFound();
});

it('authorizes via viewQueueInsightsConnection when the gate is defined', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
        ['connection' => 'redis', 'queue' => 'default'],
    ]);

    Gate::define('viewQueueInsightsConnection', static fn (?User $user, string $connection): bool => $connection === 'sqs');

    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'sqs'])
        ->assertSet('scopeConnection', 'sqs');

    // Same handler-rendering as the 404 case — AuthorizationException renders
    // as a 403 response through the default handler.
    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])
        ->assertForbidden();
});

it('preserves back-compat when viewQueueInsightsConnection is not defined', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // No Gate::define for viewQueueInsightsConnection — the second gate is
    // optional and absent today's hosts must keep working unchanged.
    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'sqs'])
        ->assertSet('scopeConnection', 'sqs');
});

it('keeps the un-scoped dashboard route working unchanged (no gate, no scope)', function (): void {
    config()->set('queue-insights.snapshots', []);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertOk()
        ->assertSet('scopeConnection', null);
});

it('hides the connection dropdown in the failed filter form when scope is set', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    $unscoped = Livewire::test(QueueInsightsDashboard::class)->html();
    $scoped = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])->html();

    // The Connection <select>'s wire:model is unique to the failed filter
    // form (`filterConnection`), so its presence/absence is a precise signal.
    expect($unscoped)->toContain('wire:model.live="filterConnection"')
        ->and($scoped)->not->toContain('wire:model.live="filterConnection"');
});

it('only iterates queues matching scope when computing rows', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    $rendered = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])->html();

    // The queue rows iterate the scoped configuredQueues — `sqs:work`
    // shouldn't surface in the queues table at all.
    expect($rendered)->toContain('redis')
        ->and($rendered)->toContain('default')
        ->and($rendered)->not->toContain('>work<');
});

it('disables the Batches section under a connection scope (per-batch keying deferred)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.batches.enabled', true);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertViewHas('batchesEnabled', true);

    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])
        ->assertViewHas('batchesEnabled', false)
        ->assertViewHas('batches', []);
});

it('CompletedRowFilter is hard-pinned to scope, ignoring completedFilterConnection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    $component = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis']);
    $component->set('completedFilterConnection', 'sqs');

    // Drive the private `buildCompletedFilter` through reflection so the
    // assertion is on the filter's public shape rather than scraping the
    // rendered HTML for the absence of `sqs` rows.
    $data = resolve(DashboardData::class);
    $reflection = new ReflectionMethod($data, 'buildCompletedFilter');
    /** @var CompletedRowFilter $filter */
    $filter = $reflection->invoke($data, $component->instance());

    expect($filter->connection)->toBe('redis');
});

it('SnapshotWatchdog suppresses cross-scope deadness', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // Seed only redis as alive — sqs has no live:depth key.
    Redis::connection('default')->command('setex', [
        KeyPrefix::make('live:depth:redis:default'),
        90,
        '0',
    ]);

    $watchdog = resolve(SnapshotWatchdog::class);

    // Un-scoped: any alive pair means "not dead" — that's pre-existing
    // behavior. Scope flips it: a redis-only-alive cluster looks dead to
    // an operator scoped to sqs but alive to one scoped to redis.
    expect($watchdog->isSnapshotCommandDead())->toBeFalse()
        ->and($watchdog->isSnapshotCommandDead('redis'))->toBeFalse()
        ->and($watchdog->isSnapshotCommandDead('sqs'))->toBeTrue();
});

it('ActiveIssuesProvider keeps class-scoped issues visible under any scope and filters queue-scoped issues by connection', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    // Pre-populate the 5s Redis cache with a known issue list. `get()` reads
    // the cache before invoking the detector, so we exercise the filter
    // without needing to mock the final `IssueDetector` class.
    $now = Date::now()
        ->getTimestamp();
    $payload = json_encode([
        ['rule' => 'depth', 'severity' => 'warning', 'connection' => 'redis', 'queue' => 'default', 'jobClass' => null, 'title' => 'Redis depth', 'description' => '...', 'context' => [], 'detectedAt' => $now],
        ['rule' => 'depth', 'severity' => 'warning', 'connection' => 'sqs', 'queue' => 'work', 'jobClass' => null, 'title' => 'Sqs depth', 'description' => '...', 'context' => [], 'detectedAt' => $now],
        // Class-scoped issues construct with connection: '' — they should
        // survive every scope filter (Phase 2 spec finding).
        ['rule' => 'failure_rate', 'severity' => 'warning', 'connection' => '', 'queue' => '', 'jobClass' => 'App\\Jobs\\Foo', 'title' => 'Failure rate high', 'description' => '...', 'context' => [], 'detectedAt' => $now],
    ]);
    Redis::connection('default')->command('setex', [
        KeyPrefix::make('alert:cache:active-issues'),
        5,
        $payload,
    ]);

    $provider = resolve(ActiveIssuesProvider::class);

    // Count assertions are sufficient given the seeded payload uniquely
    // identifies each entry: 3 issues with disjoint (rule, connection)
    // shapes mean any incorrect filter would land on a different count.
    // The Issue DTO is `@internal` so reading its properties from a Pest
    // test outside the SanderMuller namespace would fail PHPStan.
    expect($provider->get())->toHaveCount(3);

    $provider->flushMemoised();
    expect($provider->get('redis'))->toHaveCount(2);

    $provider->flushMemoised();
    expect($provider->get('sqs'))->toHaveCount(2);

    $provider->flushMemoised();
    expect($provider->get('unrelated'))->toHaveCount(1);
});

it('buildFailedFilters hard-pins connection to scope, ignoring filterConnection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    $component = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis']);

    /** @var QueueInsightsDashboard $instance */
    $instance = $component->instance();
    $instance->filterConnection = 'sqs';

    $filters = $instance->buildFailedFilters();

    expect($filters->connection)->toBe('redis');
});

it('renders the connection-scope picker with one entry per allowed connection plus All', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Gate::define('viewQueueInsights', fn (?User $user = null): bool => true);
    $user = (new User())->forceFill(['id' => 1, 'name' => 'dev', 'email' => 'dev@example.test']);

    // Picker lives in the layout header (pushed via @stack('header-scope')),
    // so HTTP rendering is required — Livewire::test() only renders the
    // component, not the layout wrapper.
    $html = test()->actingAs($user)->get('/queue-insights')->getContent();

    expect($html)->toContain('aria-label="Connection scope"')
        ->and($html)->toContain('href="http://localhost/queue-insights"')
        ->and($html)->toContain('href="http://localhost/queue-insights/redis"')
        ->and($html)->toContain('href="http://localhost/queue-insights/sqs"')
        ->and($html)->toMatch('/<code class="font-mono[^"]*">redis<\/code>/')
        ->and($html)->toMatch('/<code class="font-mono[^"]*">sqs<\/code>/');
});

it('omits the connection nav strip when only one connection is configured', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
    ]);

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    expect($html)->not->toContain('aria-label="Connection scope"');
});

it('hides gate-denied tabs from the nav strip', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue.connections.highmem', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
        ['connection' => 'highmem', 'queue' => 'reports'],
    ]);

    Gate::define('viewQueueInsights', fn (?User $user = null): bool => true);
    Gate::define('viewQueueInsightsConnection', static fn (?User $user, string $connection): bool => $connection !== 'highmem');

    // The base /queue-insights URL 403s when any monitored connection is
    // denied (codex review fix), so the nav strip drops the "All" tab too —
    // operators see only the connections they can open. Picker lives in the
    // layout header now, so render via HTTP.
    $user = (new User())->forceFill(['id' => 1, 'name' => 'dev', 'email' => 'dev@example.test']);
    $html = test()->actingAs($user)->get('/queue-insights/redis')->getContent();

    expect($html)->toContain('href="http://localhost/queue-insights/redis"')
        ->and($html)->toContain('href="http://localhost/queue-insights/sqs"')
        ->and($html)->not->toContain('href="http://localhost/queue-insights/highmem"');
});

it('403s the un-scoped base route when the per-connection gate denies any monitored connection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Gate::define('viewQueueInsightsConnection', static fn (?User $user, string $connection): bool => $connection === 'redis');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertForbidden();
});

it('keeps the un-scoped base route open when every monitored connection is allowed', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Gate::define('viewQueueInsightsConnection', fn (?User $user, string $connection): bool => true);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertOk()
        ->assertSet('scopeConnection', null);
});

it('audit log on bulk retry carries scope_connection', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    Gate::define('viewQueueInsights', fn (?User $user = null): bool => true);
    Gate::define('retryFailedJobs', fn (?User $user = null): bool => true);

    $captured = [];
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            if ($message === 'queue-insights.retry') {
                $captured = $context;

                return true;
            }

            return false;
        });
    Log::shouldReceive('warning')->andReturnNull();
    // L11/L12 prefer-lowest emit a framework-side deprecation through
    // Testbench's HandleExceptions bootstrap which routes via Log::channel.
    // Allow the call (and any chained log methods) so the mock doesn't
    // BadMethodCallException on an incidental notice unrelated to the
    // audit-shape assertion below.
    Log::shouldReceive('channel')->andReturnSelf();

    $instance = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])->instance();
    // Drive the private logRetry through reflection — the bulk-retry path
    // requires actual failed_jobs rows + Artisan call and isn't the unit
    // under test here. We just want to assert the audit shape.
    $reflection = new ReflectionMethod($instance, 'logRetry');
    $reflection->invoke($instance, 'bulk', ['uuid-a', 'uuid-b']);

    expect($captured)->toHaveKey('scope_connection', 'redis')
        ->and($captured)->toHaveKey('filters')
        ->and($captured['filters'])->toHaveKey('connection');
});
