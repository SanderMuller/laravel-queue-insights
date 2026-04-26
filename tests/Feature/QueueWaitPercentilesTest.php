<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('queueWaitPercentiles returns null/null when fewer than 10 samples exist', function (): void {
    // 9 uuids in the recency ZSET + 9 wait:{uuid} samples — under the 10 floor.
    for ($i = 1; $i <= 9; ++$i) {
        $uuid = 'uuid-' . $i;
        R::conn()->command('zadd', ['qmtest:wait:redis:default', microtime(true) + $i, $uuid]);
        R::conn()->command('setex', ['qmtest:wait:' . $uuid, 60, (string) ($i * 10)]);
    }

    $percentiles = resolve(QueueInsights::class)->queueWaitPercentiles('redis', 'default');

    expect($percentiles)->toBe(['p50' => null, 'p95' => null]);
});

it('queueWaitPercentiles joins ZSET uuids → wait:{uuid} samples and picks p50/p95', function (): void {
    // 100 jobs. ZSET stores recency (timestamp scores); wait:{uuid} keys
    // store the actual wait_ms. The percentile read MGETs the latter.
    for ($i = 1; $i <= 100; ++$i) {
        $uuid = 'uuid-' . $i;
        R::conn()->command('zadd', ['qmtest:wait:redis:default', microtime(true) + $i, $uuid]);
        R::conn()->command('setex', ['qmtest:wait:' . $uuid, 60, (string) $i]);
    }

    $percentiles = resolve(QueueInsights::class)->queueWaitPercentiles('redis', 'default');

    expect($percentiles['p50'])->toBe(50)
        ->and($percentiles['p95'])->toBe(95);
});

it('queueWaitPercentiles drops uuids whose wait sample expired (TTL gap)', function (): void {
    // 15 uuids in ZSET; only 12 have live wait:{uuid} samples. Percentile
    // floor is 10, so 12 is enough — the 3 expired ones get filtered.
    for ($i = 1; $i <= 15; ++$i) {
        R::conn()->command('zadd', ['qmtest:wait:redis:default', microtime(true) + $i, 'uuid-' . $i]);
    }

    for ($i = 1; $i <= 12; ++$i) {
        R::conn()->command('setex', ['qmtest:wait:uuid-' . $i, 60, (string) $i]);
    }

    $percentiles = resolve(QueueInsights::class)->queueWaitPercentiles('redis', 'default');

    expect($percentiles['p50'])->not->toBeNull()
        ->and($percentiles['p95'])->not->toBeNull();
});

it('jobWaitMs reads the per-job wait sample written by RecordJobProcessing', function (): void {
    R::conn()->command('setex', ['qmtest:wait:test-uuid', 60, '1234']);

    expect(resolve(QueueInsights::class)->jobWaitMs('test-uuid'))->toBe(1234);
});

it('jobWaitMs returns null when the sample is missing', function (): void {
    expect(resolve(QueueInsights::class)->jobWaitMs('missing-uuid'))->toBeNull();
});

it('jobWaitMs returns null for empty uuid input', function (): void {
    expect(resolve(QueueInsights::class)->jobWaitMs(''))->toBeNull();
});
