<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\TestJob;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.capture.payloads', 'off');
});

it('writes processed-total aggregate + per-connection on JobProcessed with refreshing 30d EXPIRE', function (): void {
    dispatch(new TestJob());
    dispatch(new TestJob());

    $class = TestJob::class;

    // 30 d = 2_592_000s. Use a comfortable lower bound so a slow CI box
    // doesn't trip the assertion.
    $minTtl = 2_500_000;

    expect(R::int('get', "qmtest:processed-total:{$class}"))->toBe(2)
        ->and(R::int('get', "qmtest:processed-total:{$class}:sync"))->toBe(2)
        ->and(R::int('ttl', "qmtest:processed-total:{$class}"))->toBeGreaterThanOrEqual($minTtl)
        ->and(R::int('ttl', "qmtest:processed-total:{$class}:sync"))->toBeGreaterThanOrEqual($minTtl);
});

it('writes failed-total aggregate + per-connection on JobFailed with refreshing 30d EXPIRE', function (): void {
    try {
        dispatch(new TestJob(shouldFail: true));
    } catch (Throwable) {
        // Sync queue surfaces the throw to the dispatcher; eat it so
        // the test continues to the assertions.
    }

    $class = TestJob::class;
    $minTtl = 2_500_000;

    expect(R::int('get', "qmtest:failed-total:{$class}"))->toBe(1)
        ->and(R::int('get', "qmtest:failed-total:{$class}:sync"))->toBe(1)
        ->and(R::int('ttl', "qmtest:failed-total:{$class}"))->toBeGreaterThanOrEqual($minTtl)
        ->and(R::int('ttl', "qmtest:failed-total:{$class}:sync"))->toBeGreaterThanOrEqual($minTtl);
});
