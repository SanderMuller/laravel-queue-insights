<?php declare(strict_types=1);

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Drivers\SqsSnapshotDriver;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('skips GetQueueUrl when the input is already a URL', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldNotReceive('getQueueUrl');
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['QueueUrl'] === 'https://sqs.eu-west-1.amazonaws.com/123/my-q'))
        ->andReturn(new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => '7',
            'ApproximateNumberOfMessagesNotVisible' => '3',
            'ApproximateNumberOfMessagesDelayed' => '2',
        ]]));

    $driver = new SqsSnapshotDriver($client, 'sqs');

    expect($driver->depth('https://sqs.eu-west-1.amazonaws.com/123/my-q'))->toBe(7)
        ->and($driver->inFlight('https://sqs.eu-west-1.amazonaws.com/123/my-q'))->toBe(3)
        ->and($driver->delayed('https://sqs.eu-west-1.amazonaws.com/123/my-q'))->toBe(2);
});

it('calls GetQueueUrl once for a name, caches the URL for 1h, and then calls GetQueueAttributes', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('getQueueUrl')
        ->once()
        ->with(['QueueName' => 'my-q'])
        ->andReturn(new Result(['QueueUrl' => 'https://sqs.eu-west-1.amazonaws.com/123/my-q']));
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->andReturn(new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => '5',
            'ApproximateNumberOfMessagesNotVisible' => '1',
            'ApproximateNumberOfMessagesDelayed' => '0',
        ]]));

    $driver = new SqsSnapshotDriver($client, 'sqs');

    expect($driver->depth('my-q'))->toBe(5)
        ->and($driver->inFlight('my-q'))->toBe(1)
        ->and($driver->delayed('my-q'))->toBe(0);

    // URL cache should be populated.
    expect(Redis::connection('default')->command('get', ['qmtest:url:sqs:my-q']))
        ->toBe('https://sqs.eu-west-1.amazonaws.com/123/my-q');
});

it('reuses a cached URL on subsequent driver instances without re-calling GetQueueUrl', function (): void {
    // Seed the cache.
    Redis::connection('default')->command('setex', [
        'qmtest:url:sqs:my-q',
        3600,
        'https://sqs.eu-west-1.amazonaws.com/123/my-q',
    ]);

    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldNotReceive('getQueueUrl');
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->andReturn(new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => '11',
            'ApproximateNumberOfMessagesNotVisible' => '0',
            'ApproximateNumberOfMessagesDelayed' => '0',
        ]]));

    $driver = new SqsSnapshotDriver($client, 'sqs');

    expect($driver->depth('my-q'))->toBe(11);
});

it('produces identical canonical keys for a URL and its bare name', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $driver = new SqsSnapshotDriver($client, 'sqs');

    expect($driver->canonicalKey('https://sqs.eu-west-1.amazonaws.com/123/my-q'))
        ->toBe($driver->canonicalKey('my-q'))
        ->toBe('my-q');
});

it('only calls GetQueueAttributes once per queue per driver instance (per-request cache)', function (): void {
    /** @var SqsClient&MockInterface $client */
    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('getQueueAttributes')
        ->once()
        ->andReturn(new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => '4',
            'ApproximateNumberOfMessagesNotVisible' => '2',
            'ApproximateNumberOfMessagesDelayed' => '1',
        ]]));

    $driver = new SqsSnapshotDriver($client, 'sqs');
    $url = 'https://sqs.eu-west-1.amazonaws.com/123/hot';

    // All three metric reads should share a single API call.
    expect($driver->depth($url))->toBe(4);
    expect($driver->inFlight($url))->toBe(2)
        ->and($driver->delayed($url))->toBe(1);
});
