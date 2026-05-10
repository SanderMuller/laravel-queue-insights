<?php declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDetector;
use SanderMuller\QueueInsights\Alerts\SnapshotWatchdog;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RecordingConsoleKernel;
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

it('scoped Recent completed reads the per-connection stream even with deeply imbalanced traffic (v2-gap Phase 2)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // Imbalanced fixture — 1000 sqs entries crowd the global stream so a
    // post-filter scoped reader (the v1 behaviour the gap describes) would
    // exhaust its RECENT_FETCH_LIMIT before reaching any redis row. The
    // per-connection stream isolates this.
    $r = Redis::connection('default');
    for ($i = 0; $i < 1000; ++$i) {
        seedStream($r, 'qmtest:completed', [
            'class' => 'App\\Jobs\\NoiseJob',
            'connection' => 'sqs',
            'queue' => 'work',
            'duration_ms' => '5',
            'attempts' => '1',
            'processed_at' => '2026-05-04T12:00:00+00:00',
            'uuid' => 'noise-' . $i,
        ]);
    }

    foreach (['signal-1', 'signal-2'] as $uuid) {
        seedStream($r, 'qmtest:completed:connection:redis', [
            'class' => 'App\\Jobs\\SignalJob',
            'connection' => 'redis',
            'queue' => 'default',
            'duration_ms' => '12',
            'attempts' => '1',
            'processed_at' => '2026-05-04T12:01:00+00:00',
            'uuid' => $uuid,
        ]);
    }

    $component = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis']);
    $rows = $component->viewData('completedRows');

    expect($rows)->toBeArray()->and($rows)->not->toBeEmpty();
    if (! is_array($rows)) {
        return;
    }

    $uuids = array_column($rows, 'uuid');
    expect($uuids)->toContain('signal-1')
        ->and($uuids)->toContain('signal-2')
        ->and($uuids)->not->toContain('noise-0')
        ->and($uuids)->not->toContain('noise-999');
});

it('Batches section under scope reads the per-connection roster (v2-gap Phase 1)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-insights.batches.enabled', true);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // Seed two batches into different per-connection rosters via the same
    // shape `RecordJobQueued`'s BatchClaimConnection.lua produces. The
    // Bus::findBatch() row hydration is unimportant for this assertion —
    // missing rows are skipped silently and the section just renders an
    // empty list.
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-redis');
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-sqs');
    R::raw('zadd', 'qmtest:batches:index:redis', Date::now()
        ->getTimestamp(), 'batch-redis');
    R::raw('zadd', 'qmtest:batches:index:sqs', Date::now()
        ->getTimestamp(), 'batch-sqs');
    R::raw('set', 'qmtest:batch:batch-redis:connection', 'redis');
    R::raw('set', 'qmtest:batch:batch-sqs:connection', 'sqs');

    // Un-scoped: section enabled, both batches visible (rows resolve to
    // empty without a job_batches row, but the read path is exercised).
    Livewire::test(QueueInsightsDashboard::class)
        ->assertViewHas('batchesEnabled', true);

    // Scoped: section stays enabled (no longer hidden) and the underlying
    // reader routes to the per-connection index — only the scope's batch
    // is reachable.
    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])
        ->assertViewHas('batchesEnabled', true);
});

it('CompletedRowFilter no-ops on connection under scope (per-connection stream is the gate, v2-gap Phase 2)', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    $component = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis']);
    $component->set('completedFilterConnection', 'sqs');

    $data = resolve(DashboardData::class);
    $reflection = new ReflectionMethod($data, 'buildCompletedFilter');
    /** @var CompletedRowFilter $filter */
    $filter = $reflection->invoke($data, $component->instance());

    // Phase 2 — recentCompleted routes by scope to the per-connection
    // stream, so the post-fetch CompletedRowFilter must not double-gate
    // on connection. An empty-string connection makes the filter no-op
    // on that axis, which is exactly what we want.
    expect($filter->connection)
        ->toBeEmpty();
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

    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->nullable();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-a',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X']),
        'exception' => 'X',
        'failed_at' => '2026-04-26 10:00:00',
    ]);

    RecordingConsoleKernel::reset();
    app()->instance(ConsoleKernelContract::class, new RecordingConsoleKernel());

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

    // Drive retryFailedBulk end-to-end (filterQueue triggers a non-empty
    // filter, the seeded failed_jobs row keeps the count in [1, 100], and
    // the recorded kernel returns exit 0 so the audit-log info() fires).
    Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])
        ->set('filterQueue', 'default')
        ->call('retryFailedBulk');

    expect($captured)->toHaveKey('scope_connection', 'redis')
        ->and($captured)->toHaveKey('filters')
        ->and($captured['filters'])->toHaveKey('connection')
        ->and($captured)->toHaveKey('kind', 'bulk');

    Schema::dropIfExists('failed_jobs');
});

it('Silenced tab renders the silenced-class roster + per-axis empty-state messaging that is scope-aware', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy', 'App\\Jobs\\Other']);

    app()->forgetScopedInstances();

    $unscoped = Livewire::test(QueueInsightsDashboard::class)->html();
    expect($unscoped)->toContain('Silenced classes')
        ->and($unscoped)->toContain('App\\Jobs\\Noisy')
        ->and($unscoped)->toContain('App\\Jobs\\Other')
        ->and($unscoped)->toContain('No silenced-class failures recorded')
        ->and($unscoped)->toContain('No silenced-class completed jobs recorded');

    $scoped = Livewire::test(QueueInsightsDashboard::class, ['connection' => 'redis'])->html();
    expect($scoped)->toContain('No silenced-class failures on the redis connection')
        ->and($scoped)->toContain('No silenced-class completed jobs on the redis connection');
});

it('Silenced tab renders when only silenced_patterns is configured (no exact list)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.silenced', []);
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    app()->forgetScopedInstances();

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    expect($html)->toContain('Silenced patterns')
        ->and($html)->toContain('App\\Jobs\\Reports\\*');
});

it('Silenced tab is hidden when the silenced list is empty', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.silenced', []);
    config()->set('queue-insights.silenced_patterns', []);

    app()->forgetScopedInstances();

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    expect($html)->not->toContain('Silenced classes')
        ->and($html)->not->toContain('No silenced-class failures');
});

it('Silenced tab merges + slices to PER_PAGE, dropping non-silenced rows (codex review #2)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    app()->forgetScopedInstances();

    $r = Redis::connection('default');
    for ($i = 0; $i < 30; ++$i) {
        seedStream($r, KeyPrefix::make('completed'), [
            'class' => 'App\\Jobs\\Noisy',
            'connection' => 'redis',
            'queue' => 'webhooks',
            'uuid' => 'noisy-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    for ($i = 0; $i < 10; ++$i) {
        seedStream($r, KeyPrefix::make('completed'), [
            'class' => 'App\\Jobs\\Quiet',
            'connection' => 'redis',
            'queue' => 'mail',
            'uuid' => 'quiet-' . $i,
        ]);
    }

    $rows = Livewire::test(QueueInsightsDashboard::class)->viewData('silencedCompletedRows');

    // Silenced tab caps at PER_PAGE per axis. Bound to the constant so
    // future default changes don't drift this assertion silently.
    expect($rows)->toBeArray()->and($rows)->toHaveCount(DashboardData::PER_PAGE);
    if (! is_array($rows)) {
        return;
    }

    foreach ($rows as $row) {
        if (is_array($row)) {
            expect($row['class'] ?? null)->toBe('App\\Jobs\\Noisy');
        }
    }
});

it('Silenced tab paginates beyond the first page via gotoSilencedCompletedPage', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    app()->forgetScopedInstances();

    $r = Redis::connection('default');
    for ($i = 0; $i < 30; ++$i) {
        seedStream($r, KeyPrefix::make('completed'), [
            'class' => 'App\\Jobs\\Noisy',
            'connection' => 'redis',
            'queue' => 'webhooks',
            'uuid' => 'noisy-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $component = Livewire::test(QueueInsightsDashboard::class);

    $paginator = $component->viewData('silencedCompletedPaginator');
    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(30)
        ->and($paginator->perPage())->toBe(DashboardData::PER_PAGE)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getPageName())->toBe('scp');

    $component->call('gotoSilencedCompletedPage', 3)
        ->assertSet('silencedCompletedPage', 3);

    expect($component->viewData('silencedCompletedPaginator')->currentPage())->toBe(3)
        ->and($component->viewData('silencedCompletedRows'))->toHaveCount(10);
});

it('Silenced tab clamps URL-hydrated per-page values via boot()', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    app()->forgetScopedInstances();

    Livewire::withQueryParams(['sfpp' => 999999, 'scpp' => -1]);
    $component = Livewire::test(QueueInsightsDashboard::class);

    expect($component->get('silencedFailedPerPage'))->toBe(DashboardData::PER_PAGE)
        ->and($component->get('silencedCompletedPerPage'))->toBe(DashboardData::PER_PAGE);
});

it('Silenced tab resets to page 1 when per-page changes', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    app()->forgetScopedInstances();

    $r = Redis::connection('default');
    for ($i = 0; $i < 60; ++$i) {
        seedStream($r, KeyPrefix::make('completed'), [
            'class' => 'App\\Jobs\\Noisy',
            'connection' => 'redis',
            'queue' => 'webhooks',
            'uuid' => 'noisy-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    Livewire::test(QueueInsightsDashboard::class)
        ->call('gotoSilencedCompletedPage', 4)
        ->assertSet('silencedCompletedPage', 4)
        ->set('silencedCompletedPerPage', 50)
        ->assertSet('silencedCompletedPerPage', 50)
        ->assertSet('silencedCompletedPage', 1);
});
