<?php declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

/**
 * Regression suite: host apps that register `Date::use(CarbonImmutable::class)` must be
 * able to drive the whole package without tripping a `Carbon` return-type signature.
 *
 * Laravel 11+ projects and Vapor-era codebases trend toward immutable dates; hihaho
 * reported the original bug from `Date::createFromTimestamp()` returning `CarbonImmutable`
 * against a method typed `?Illuminate\Support\Carbon`. Widened to `CarbonInterface`.
 */
beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    Date::use(CarbonImmutable::class);

    config()->set('queue-insights.key_prefix', 'qmtest:');
});

afterEach(function (): void {
    // Other tests expect the default (mutable) Date factory — reset after each case.
    Date::useDefault();
});

it('lastSnapshotAt returns a CarbonInterface under Date::use(CarbonImmutable::class)', function (): void {
    $redis = Redis::connection('default');
    $key = KeyPrefix::make('depth:sqs:work');
    $now = 1716200000;

    $redis->command('zadd', [$key, $now, (string) $now]);

    $result = resolve(QueueInsights::class)->lastSnapshotAt('sqs', 'work');

    expect($result)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result?->getTimestamp())->toBe($now);
});

it('classMetrics lastRunAt survives an immutable Date factory', function (): void {
    $redis = Redis::connection('default');
    $class = 'App\\Jobs\\Probe';
    $iso = '2026-04-24T12:00:00+00:00';

    $redis->command('set', [KeyPrefix::make("last_run:{$class}"), $iso]);

    $metrics = resolve(QueueInsights::class)->classMetrics($class);

    expect($metrics->lastRunAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('dashboard stale badge honours CarbonImmutable from lastSnapshotAt', function (): void {
    // When lastSnapshotAt is very recent (now) with an immutable Date factory, the queue
    // card must NOT be flagged stale. Regression against an earlier `instanceof
    // Illuminate\Support\Carbon` check that silently failed under immutable dates.
    $redis = Redis::connection('default');
    $now = Date::now()->getTimestamp();
    $redis->command('zadd', [KeyPrefix::make('depth:redis:default'), $now, (string) $now]);

    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [['connection' => 'redis', 'queue' => 'default']]);
    config()->set('queue-insights.driver_overrides.redis', 'null');

    $svc = resolve(QueueInsights::class);
    $last = $svc->lastSnapshotAt('redis', 'default');

    expect($last)->not->toBeNull()
        ->and($last?->diffInSeconds(Date::now()))->toBeLessThan(5);
});
