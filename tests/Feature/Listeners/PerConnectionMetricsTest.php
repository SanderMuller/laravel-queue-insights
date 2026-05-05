<?php declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\TestJob;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.capture.payloads', 'off');
});

function pcmBucket(): string
{
    return CarbonImmutable::now('UTC')->format('YmdH');
}

it('RecordJobProcessed dual-writes processed counter under (class) and (class, connection) keys', function (): void {
    dispatch(new TestJob());

    $bucket = pcmBucket();
    $class = TestJob::class;

    expect(R::int('get', "qmtest:processed:{$class}:{$bucket}"))->toBe(1)
        ->and(R::int('get', "qmtest:processed:{$class}:sync:{$bucket}"))->toBe(1);
});

it('RecordJobProcessed dual-writes duration hash + samples list per connection', function (): void {
    dispatch(new TestJob());

    $class = TestJob::class;

    expect(R::int('hget', "qmtest:duration:{$class}", 'count'))->toBe(1)
        ->and(R::int('hget', "qmtest:duration:{$class}:sync", 'count'))->toBe(1)
        ->and(R::int('llen', "qmtest:duration:samples:{$class}"))->toBe(1)
        ->and(R::int('llen', "qmtest:duration:samples:{$class}:sync"))->toBe(1);
});

it('RecordJobProcessed populates classes:{connection} ZSET alongside the global classes ZSET', function (): void {
    dispatch(new TestJob());

    $class = TestJob::class;

    expect(R::raw('zrange', 'qmtest:classes', 0, -1))->toContain($class)
        ->and(R::raw('zrange', 'qmtest:classes:sync', 0, -1))->toContain($class);
});

it('RecordJobProcessed dual-writes last_run per connection', function (): void {
    dispatch(new TestJob());

    $class = TestJob::class;

    expect(R::raw('get', "qmtest:last_run:{$class}"))->toBeString()
        ->and(R::raw('get', "qmtest:last_run:{$class}:sync"))->toBeString();
});

it('RecordJobFailed dual-writes failed counter and classes:{connection} on JobFailed', function (): void {
    try {
        dispatch(new TestJob(shouldFail: true));
    } catch (Throwable) {
        // sync driver rethrows
    }

    $bucket = pcmBucket();
    $class = TestJob::class;

    expect(R::int('get', "qmtest:failed:{$class}:{$bucket}"))->toBe(1)
        ->and(R::int('get', "qmtest:failed:{$class}:sync:{$bucket}"))->toBe(1)
        ->and(R::raw('zrange', 'qmtest:classes:sync', 0, -1))->toContain($class)
        ->and(R::raw('get', "qmtest:last_run:{$class}:sync"))->toBeString();
});

it('classes:{connection} ZSET expires at 30d (per-event re-bumped)', function (): void {
    dispatch(new TestJob());

    $ttl = R::int('ttl', 'qmtest:classes:sync');

    expect($ttl)->toBeGreaterThan(2592000 - 3600)
        ->toBeLessThanOrEqual(2592000);
});

it('RecordJobProcessed writes the per-connection completed stream alongside global + per-class', function (): void {
    dispatch(new TestJob());

    // All three streams populated with one entry, MAXLEN ~ doesn't matter
    // for the first write.
    expect(R::int('xlen', 'qmtest:completed'))->toBe(1)
        ->and(R::int('xlen', 'qmtest:completed:' . TestJob::class))->toBe(1)
        ->and(R::int('xlen', 'qmtest:completed:connection:sync'))->toBe(1);
});

it('per-connection completed stream entry carries the same connection field as the global stream', function (): void {
    dispatch(new TestJob());

    $globalEntries = R::raw('xrevrange', 'qmtest:completed', '+', '-', 1);
    $perConnEntries = R::raw('xrevrange', 'qmtest:completed:connection:sync', '+', '-', 1);

    expect($globalEntries)->not->toBeEmpty()
        ->and($perConnEntries)->not->toBeEmpty();

    $globalFields = is_array($globalEntries) ? array_values($globalEntries)[0] : [];
    $perConnFields = is_array($perConnEntries) ? array_values($perConnEntries)[0] : [];

    expect($globalFields)->toHaveKey('connection')
        ->and($perConnFields)->toHaveKey('connection')
        ->and($globalFields['connection'] ?? null)->toBe('sync')
        ->and($perConnFields['connection'] ?? null)->toBe('sync');
});

it('listener writes only aggregate keys when connectionName is empty (no trailing-colon keys)', function (): void {
    // Synthesise a JobProcessed event with an empty connectionName via a
    // fake Job whose getConnectionName returns ''. Aggregate writes must
    // still happen; per-connection writes must be skipped to avoid keys
    // like `processed:{class}::{bucket}` / `classes:` / `last_run:{class}:`
    // (codex review #1).
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('synthetic-uuid');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn(['data' => []]);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Synth');

    $event = new JobProcessed(connectionName: '', job: $job);

    /** @var RecordJobProcessed $listener */
    $listener = resolve(RecordJobProcessed::class);
    $listener->handle($event);

    // No trailing-colon variants — these would be the bug-paths.
    $bucket = CarbonImmutable::now('UTC')->format('YmdH');
    expect(R::int('exists', 'qmtest:processed:App\\Jobs\\Synth::' . $bucket))->toBe(0)
        ->and(R::int('exists', 'qmtest:duration:App\\Jobs\\Synth:'))->toBe(0)
        ->and(R::int('exists', 'qmtest:duration:samples:App\\Jobs\\Synth:'))->toBe(0)
        ->and(R::int('exists', 'qmtest:last_run:App\\Jobs\\Synth:'))->toBe(0)
        ->and(R::int('exists', 'qmtest:classes:'))->toBe(0)
        ->and(R::int('exists', 'qmtest:completed:connection:'))->toBe(0)
        // Aggregate writes still happen.
        ->and(R::int('get', 'qmtest:processed:App\\Jobs\\Synth:' . $bucket))->toBe(1);
});
