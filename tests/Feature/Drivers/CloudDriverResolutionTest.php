<?php declare(strict_types=1);

use Aws\Credentials\Credentials;
use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Drivers\NullSnapshotDriver;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Drivers\SqsSnapshotDriver;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ConfiguredQueueList;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use SanderMuller\QueueInsights\Tests\Support\CloudQueueConfig;

beforeEach(function (): void {
    config()->set('queue.connections.cloud', CloudQueueConfig::make());
});

it('unwraps the cloud driver to the nested connection it delegates to', function (): void {
    expect((new QueueSnapshotDriverFactory())->make('cloud'))
        ->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('does not log an unknown-driver warning for the cloud driver', function (): void {
    Log::shouldReceive('warning')->never();

    (new QueueSnapshotDriverFactory())->make('cloud');
});

it('reads counts off the suffixed queue URL without calling GetQueueUrl', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldNotReceive('getQueueUrl');
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['QueueUrl'] === CloudQueueConfig::url('default')))
        ->andReturn(new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => '9',
            'ApproximateNumberOfMessagesNotVisible' => '4',
            'ApproximateNumberOfMessagesDelayed' => '1',
        ]]));

    $driver = new SqsSnapshotDriver($client, 'cloud', CloudQueueConfig::PREFIX, CloudQueueConfig::SUFFIX);

    // The logical name is what `snapshots[]` carries; the suffix is applied
    // only when addressing AWS.
    expect($driver->depth('default'))->toBe(9)
        ->and($driver->inFlight('default'))->toBe(4)
        ->and($driver->delayed('default'))->toBe(1);
});

it('keeps the suffix before the .fifo marker AWS requires last', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['QueueUrl'] === CloudQueueConfig::url('orders.fifo')))
        ->andReturn(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '3']]));

    $driver = new SqsSnapshotDriver($client, 'cloud', CloudQueueConfig::PREFIX, CloudQueueConfig::SUFFIX);

    expect($driver->depth('orders.fifo'))->toBe(3);
});

it('keys snapshots on the logical name the worker event path resolves to', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $driver = new SqsSnapshotDriver($client, 'cloud');

    // What the worker sees on the job: `SqsJob::getQueue()` returns the queue
    // URL, so it carries the physical (suffixed) name.
    $eventQueue = CloudQueueConfig::url('default');

    expect($driver->canonicalKey('default'))
        ->toBe(CanonicalQueueKey::forConnection($eventQueue, 'cloud'))
        ->toBe('default');
});

it('keys a snapshot entry written under the physical name the same way', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $driver = new SqsSnapshotDriver($client, 'cloud');

    expect($driver->canonicalKey('default-abc123'))->toBe('default')
        ->and($driver->canonicalKey('orders-abc123.fifo'))->toBe('orders_fifo');
});

it('resolves credentials through the provider the connection names', function (): void {
    config()->set('queue.connections.cloud', CloudQueueConfig::make([
        'connection' => CloudQueueConfig::connection(['credentials' => 'ecs']),
    ]));

    // No key/secret pair anywhere: the driver still builds rather than throwing
    // on the unresolved credentials, exactly as the Cloud connector does.
    expect((new QueueSnapshotDriverFactory())->make('cloud'))
        ->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('ignores an unrecognised credentials provider instead of failing the tick', function (): void {
    config()->set('queue.connections.cloud', CloudQueueConfig::make([
        'connection' => CloudQueueConfig::connection(['credentials' => 'not-a-provider']),
    ]));

    expect((new QueueSnapshotDriverFactory())->make('cloud'))
        ->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('falls back to NullSnapshotDriver when the nested connection config is missing', function (): void {
    config()->set('queue.connections.cloud', ['driver' => 'cloud', 'queue' => 'default']);

    Log::shouldReceive('warning')
        ->once()
        ->with('queue-insights: unknown queue driver; using NullSnapshotDriver', Mockery::any());

    expect((new QueueSnapshotDriverFactory())->make('cloud'))->toBeInstanceOf(NullSnapshotDriver::class);
});

it('falls back to NullSnapshotDriver when cloud wraps a backend other than sqs', function (): void {
    config()->set('queue.connections.cloud', CloudQueueConfig::make([
        'connection' => ['driver' => 'beanstalkd'],
    ]));

    Log::shouldReceive('warning')
        ->once()
        ->with('queue-insights: unknown queue driver; using NullSnapshotDriver', Mockery::on(
            fn (array $context): bool => $context['driver'] === 'cloud/beanstalkd'
        ));

    expect((new QueueSnapshotDriverFactory())->make('cloud'))->toBeInstanceOf(NullSnapshotDriver::class);
});

it('honors a driver_override naming the cloud driver', function (): void {
    config()->set('queue.connections.wrapped', CloudQueueConfig::make(['driver' => 'beanstalkd']));
    config()->set('queue-insights.driver_overrides.wrapped', 'cloud');

    expect((new QueueSnapshotDriverFactory())->make('wrapped'))
        ->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('rejects a snapshot entry listing the same queue under both spellings', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'cloud', 'queue' => 'stats'],
            ['connection' => 'cloud', 'queue' => 'stats' . CloudQueueConfig::SUFFIX],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'collision');
});

it('rosters a physically-named snapshot entry, keyed logically', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'cloud', 'queue' => 'stats' . CloudQueueConfig::SUFFIX],
    ]);

    // The roster keeps the operator's spelling for display...
    expect(array_column(ConfiguredQueueList::build(), 'queue'))
        ->toBe(['stats' . CloudQueueConfig::SUFFIX]);

    // ...while every consumer resolves it to the key the snapshot driver
    // writes its metrics under, so the row and the metric meet.
    expect(CanonicalQueueKey::forConnection('stats' . CloudQueueConfig::SUFFIX, 'cloud'))
        ->toBe('stats');
});

it('resolves a physically-named snapshot entry to the physical URL, suffix applied once', function (): void {
    // Codex flagged this as a double-suffix risk; `Str::finish` caps rather
    // than appends, so the URL stays correct — pinned here so it stays that way.
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['QueueUrl'] === CloudQueueConfig::url('stats')))
        ->andReturn(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '5']]));

    $driver = new SqsSnapshotDriver($client, 'cloud', CloudQueueConfig::PREFIX, CloudQueueConfig::SUFFIX);

    expect($driver->depth('stats' . CloudQueueConfig::SUFFIX))->toBe(5);
});

it('hands a callable credentials provider to the SDK unchanged', function (): void {
    // `SqsConnector` passes a callable provider straight through; falling back
    // to the default chain here would authenticate as a different principal
    // than the worker.
    config()->set('queue.connections.cloud', CloudQueueConfig::make([
        'connection' => CloudQueueConfig::connection([
            'credentials' => fn (): mixed => null,
        ]),
    ]));

    expect((new QueueSnapshotDriverFactory())->make('cloud'))
        ->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('prefers a configured credentials provider over a legacy key/secret pair', function (): void {
    // Same precedence as `SqsConnector::connect()`: a provider wins over the
    // static pair, so the snapshot client can't end up on a different account
    // than the worker. Asserted through a callable provider, which is the one
    // shape resolvable without a live metadata endpoint.
    $marker = new Credentials('provider-key', 'provider-secret');

    config()->set('queue.connections.cloud', CloudQueueConfig::make([
        'connection' => CloudQueueConfig::connection([
            'credentials' => fn (): PromiseInterface => Create::promiseFor($marker),
            'key' => 'legacy-key',
            'secret' => 'legacy-secret',
        ]),
    ]));

    $driver = (new QueueSnapshotDriverFactory())->make('cloud');
    $client = (new ReflectionProperty($driver, 'client'))->getValue($driver);

    expect($client)->toBeInstanceOf(SqsClient::class)
        ->and($client->getCredentials()->wait()->getAccessKeyId())->toBe('provider-key');
});
