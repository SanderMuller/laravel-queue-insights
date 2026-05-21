<?php declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Http\Middleware\SetInitiatorOrigin;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Listeners\SetInitiatorOriginFromCommand;
use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\StreamEntries;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.initiator.enabled', true);
    config()->set('queue-insights.initiator.capture_origin', true);
    config()->set('queue-insights.initiator.ttl_seconds', 604800);
    config()->set('queue-insights.initiator.context_key', 'qi_origin');

    // Each test starts with a clean Context — listeners read hidden entries.
    Context::flush();
});

afterEach(function (): void {
    Context::flush();
});

/**
 * Build a JobQueued event whose payload carries the given uuid.
 */
function makeInitiatorQueuedEvent(string $uuid, string $connection = 'redis', string $queue = 'work'): JobQueued
{
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\InitiatorTestJob']);

    return new JobQueued(
        connectionName: $connection,
        queue: $queue,
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => 'App\\Jobs\\InitiatorTestJob'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );
}

/**
 * Job mock that answers every method RecordJobProcessed / RecordJobFailed touch.
 */
function makeInitiatorJobMock(string $uuid, string $queue = 'work'): Job&MockInterface
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\InitiatorTestJob']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\InitiatorTestJob');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn($uuid);

    return $job;
}

// --- Origin resolution: HTTP / artisan / schedule --------------------------

it('the CommandStarting listener stamps artisan:{command} onto hidden Context', function (): void {
    (new SetInitiatorOriginFromCommand())->handle(new CommandStarting(
        'app:sync-orders',
        new ArrayInput([]),
        new NullOutput(),
    ));

    expect(Context::getHidden('qi_origin'))->toBe('artisan:app:sync-orders');
});

it('a job queued during an artisan command inherits artisan:{command} as its origin', function (): void {
    (new SetInitiatorOriginFromCommand())->handle(new CommandStarting(
        'app:sync-orders',
        new ArrayInput([]),
        new NullOutput(),
    ));

    $uuid = '01ARZ3NDEKTSV4RRFFQ69ARTISAN';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'origin'))->toBe('artisan:app:sync-orders');
});

it('an http: origin set on Context lands on the pending hash', function (): void {
    // The middleware writes `http:{route}` to Context; assert RecordJobQueued
    // copies whatever origin is present onto the pending row.
    Context::addHidden('qi_origin', 'http:checkout.store');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69HTTP';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'origin'))->toBe('http:checkout.store');
});

it('a schedule: origin set on Context lands on the pending hash', function (): void {
    Context::addHidden('qi_origin', 'schedule:backup-db');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69SCHED';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'origin'))->toBe('schedule:backup-db');
});

// --- Propagation + worker isolation ----------------------------------------

it('a job dispatched from inside a running job inherits the parent origin via Context', function (): void {
    // Worker restores the parent's Context before running it; a nested
    // dispatch fires JobQueued while that Context is still live.
    Context::addHidden('qi_origin', 'http:checkout.store');

    $childUuid = '01ARZ3NDEKTSV4RRFFQ69NESTED';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($childUuid));

    expect(R::str('hget', 'qmtest:pending:' . $childUuid, 'origin'))->toBe('http:checkout.store');
});

it('two unrelated jobs back-to-back on one worker are NOT cross-attributed', function (): void {
    // Job N runs with its restored origin.
    Context::addHidden('qi_origin', 'http:checkout.store');
    $firstUuid = '01ARZ3NDEKTSV4RRFFQ69WORKER1';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($firstUuid));

    // Worker finishes job N and restores job N+1's Context (which carries
    // no origin — dispatched from tinker / a daemon). Laravel restores
    // each job's context from its own payload; simulate by flushing.
    Context::flush();

    $secondUuid = '01ARZ3NDEKTSV4RRFFQ69WORKER2';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($secondUuid));

    // First row keeps its origin; the second is NOT attributed to the first.
    expect(R::str('hget', 'qmtest:pending:' . $firstUuid, 'origin'))->toBe('http:checkout.store')
        ->and(R::int('hexists', 'qmtest:pending:' . $secondUuid, 'origin'))->toBe(0);
});

// --- Daemon skip-list ------------------------------------------------------

it('daemon commands do not set an artisan origin', function (): void {
    foreach (['queue:work', 'queue:listen', 'horizon', 'horizon:work', 'queue-insights:work', 'schedule:work'] as $daemon) {
        Context::flush();

        (new SetInitiatorOriginFromCommand())->handle(new CommandStarting(
            $daemon,
            new ArrayInput([]),
            new NullOutput(),
        ));

        expect(Context::getHidden('qi_origin'))->toBeNull();
    }
});

// --- Disabled toggles ------------------------------------------------------

it('writes no origin when initiator.enabled is false', function (): void {
    config()->set('queue-insights.initiator.enabled', false);
    Context::addHidden('qi_origin', 'http:checkout.store');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69DISABLED';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    expect(R::int('hexists', 'qmtest:pending:' . $uuid, 'origin'))->toBe(0);
});

it('writes no origin when initiator.capture_origin is false', function (): void {
    config()->set('queue-insights.initiator.capture_origin', false);
    Context::addHidden('qi_origin', 'http:checkout.store');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOCAP';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    expect(R::int('hexists', 'qmtest:pending:' . $uuid, 'origin'))->toBe(0);
});

it('the CommandStarting listener writes nothing when capture is off', function (): void {
    config()->set('queue-insights.initiator.capture_origin', false);

    (new SetInitiatorOriginFromCommand())->handle(new CommandStarting(
        'app:sync-orders',
        new ArrayInput([]),
        new NullOutput(),
    ));

    expect(Context::getHidden('qi_origin'))->toBeNull();
});

// --- RecordJobProcessed: origin on the completed stream --------------------

it('RecordJobProcessed copies the origin onto the completed stream entry', function (): void {
    Context::addHidden('qi_origin', 'http:checkout.store');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69COMPLETED';
    resolve(RecordJobProcessed::class)->handle(
        new JobProcessed(connectionName: 'redis', job: makeInitiatorJobMock($uuid)),
    );

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(1);

    $fields = array_values($entries)[0];
    expect($fields['origin'] ?? null)->toBe('http:checkout.store');
});

it('RecordJobProcessed omits origin when none is on Context', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOORIGIN';
    resolve(RecordJobProcessed::class)->handle(
        new JobProcessed(connectionName: 'redis', job: makeInitiatorJobMock($uuid)),
    );

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(1);

    $fields = array_values($entries)[0];
    expect(array_key_exists('origin', $fields))->toBeFalse();
});

// --- RecordJobFailed: durable initiator key + lazy resolve -----------------

it('RecordJobFailed persists the origin into qi:initiator:{uuid}', function (): void {
    Context::addHidden('qi_origin', 'http:checkout.store');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILED';
    (new RecordJobFailed(resolve(ResolveJobClass::class)))->handle(
        new JobFailed(connectionName: 'redis', job: makeInitiatorJobMock($uuid), exception: new RuntimeException('boom')),
    );

    expect(R::str('hget', 'qmtest:initiator:' . $uuid, 'origin'))->toBe('http:checkout.store');

    // The failed-modal lazy resolve reads it back via InitiatorStore.
    expect((new InitiatorStore())->read($uuid)['origin'])->toBe('http:checkout.store');
});

it('RecordJobFailed writes no initiator key when no origin is on Context', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILNONE';
    (new RecordJobFailed(resolve(ResolveJobClass::class)))->handle(
        new JobFailed(connectionName: 'redis', job: makeInitiatorJobMock($uuid), exception: new RuntimeException('boom')),
    );

    expect(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(0);
});

// --- SetInitiatorOrigin middleware: closure-route method+URI fallback ------

/**
 * Drive the SetInitiatorOrigin middleware against a request bound to the
 * given route, and return the origin it stamped onto hidden Context.
 */
function originForRoute(Route $route, string $method, string $uri): ?string
{
    $request = Request::create('/' . ltrim($uri, '/'), $method);
    $request->setRouteResolver(static fn (): Route => $route);

    (new SetInitiatorOrigin())->handle(
        $request,
        static fn (): Response => new Response(),
    );

    $origin = Context::getHidden('qi_origin');

    return is_string($origin) ? $origin : null;
}

it('a bare closure route with no name falls back to http:{METHOD} {uri}', function (): void {
    // No name, no controller — just a closure action.
    $route = (new Route(['GET'], 'orders/{order}', ['uses' => static fn (): string => 'ok']));

    $origin = originForRoute($route, 'GET', 'orders/5');

    expect($origin)->toBe('http:GET orders/{order}');
});

it('a named route still wins over the method+URI fallback', function (): void {
    $route = (new Route(['GET'], 'orders/{order}', ['uses' => static fn (): string => 'ok']))
        ->name('orders.show');

    $origin = originForRoute($route, 'GET', 'orders/5');

    expect($origin)->toBe('http:orders.show');
});

it('a controller action still wins over the method+URI fallback', function (): void {
    $route = new Route(['POST'], 'orders', ['controller' => 'App\\Http\\Controllers\\OrderController@store']);

    $origin = originForRoute($route, 'POST', 'orders');

    expect($origin)->toBe('http:App\\Http\\Controllers\\OrderController@store');
});

// --- PendingJobsReader surfaces the field ----------------------------------

it('PendingJobsReader::findByUuid surfaces the origin on the pending row', function (): void {
    Context::addHidden('qi_origin', 'artisan:app:sync-orders');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69READER';
    (new RecordJobQueued())->handle(makeInitiatorQueuedEvent($uuid));

    $row = PendingJobsReader::findByUuid($uuid);
    expect($row)->not->toBeNull()
        ->and($row['origin'] ?? null)->toBe('artisan:app:sync-orders');
});
