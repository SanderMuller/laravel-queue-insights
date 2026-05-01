<?php declare(strict_types=1);

use Carbon\CarbonImmutable;
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
