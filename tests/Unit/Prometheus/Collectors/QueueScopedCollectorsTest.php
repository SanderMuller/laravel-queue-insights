<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Prometheus\Collectors\DelayedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\InflightCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\OldestInflightAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\OldestPendingAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\PendingCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\QueueDepthCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAliveCollector;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
});

it('queue depth collector reads live:depth and emits one sample per snapshot pair', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('live:depth:sqs:work'), 90, '42']);

    $families = (new QueueDepthCollector())->collect();
    expect($families)->toHaveCount(1);

    $family = $families[0];
    expect($family->name)->toBe('queue_insights_queue_depth')
        ->and($family->type)
        ->toBe('gauge')
        ->and($family->samples)
        ->toHaveCount(1)
        ->and($family->samples[0]->value)
        ->toBe(42.0)
        ->and($family->samples[0]->labels)
        ->toBe(['connection' => 'sqs', 'queue' => 'work']);
});

it('inflight collector reads ZCARD on inflight-zset', function (): void {
    R::conn()->command('zadd', [KeyPrefix::make('inflight-zset:sqs:work'), 100, 'uuid-a', 200, 'uuid-b']);

    $samples = (new InflightCollector())->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)
        ->toBe(2.0);
});

it('pending vs delayed split by available_at across now', function (): void {
    Date::setTestNow('2026-05-05 12:00:00');
    $now = Date::now()->getTimestamp();

    // 2 jobs runnable now (score <= now), 1 delayed (score > now)
    R::conn()->command('zadd', [
        KeyPrefix::make('pending-zset:sqs:work'),
        $now - 10, 'past-1',
        $now, 'past-2',
        $now + 60, 'future',
    ]);

    $pending = (new PendingCollector())->collect()[0]->samples[0]->value;
    $delayed = (new DelayedCollector())->collect()[0]->samples[0]->value;

    expect($pending)->toBe(2.0)
        ->and($delayed)
        ->toBe(1.0);

    Date::setTestNow();
});

it('oldest pending age = now - min runnable score, 0 when empty', function (): void {
    Date::setTestNow('2026-05-05 12:00:00');
    $now = Date::now()->getTimestamp();

    expect((new OldestPendingAgeCollector())->collect()[0]->samples[0]->value)->toBe(0.0);

    R::conn()->command('zadd', [
        KeyPrefix::make('pending-zset:sqs:work'),
        $now - 30, 'old',
        $now - 5, 'newer',
    ]);

    expect((new OldestPendingAgeCollector())->collect()[0]->samples[0]->value)->toBe(30.0);

    Date::setTestNow();
});

it('oldest inflight age reads zset head score', function (): void {
    Date::setTestNow('2026-05-05 12:00:00');
    $now = Date::now()->getTimestamp();

    R::conn()->command('zadd', [
        KeyPrefix::make('inflight-zset:sqs:work'),
        $now - 7, 'a',
        $now - 2, 'b',
    ]);

    expect((new OldestInflightAgeCollector())->collect()[0]->samples[0]->value)->toBe(7.0);

    Date::setTestNow();
});

it('snapshot alive emits 1 when live:depth exists and 0 otherwise', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'alive'],
        ['connection' => 'sqs', 'queue' => 'dead'],
    ]);

    R::conn()->command('setex', [KeyPrefix::make('live:depth:sqs:alive'), 90, '0']);

    $samples = (new SnapshotAliveCollector())->collect()[0]->samples;
    $byQueue = [];
    foreach ($samples as $s) {
        $byQueue[$s->labels['queue']] = $s->value;
    }

    expect($byQueue)->toBe(['alive' => 1.0, 'dead' => 0.0]);
});

it('snapshot age omits the sample when live:depth is absent (no clamp-to-zero)', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'alive'],
        ['connection' => 'sqs', 'queue' => 'dead'],
    ]);

    R::conn()->command('setex', [KeyPrefix::make('live:depth:sqs:alive'), 90, '0']);

    $samples = (new SnapshotAgeCollector())->collect()[0]->samples;
    expect($samples)->toHaveCount(1)
        ->and($samples[0]->labels)
        ->toBe(['connection' => 'sqs', 'queue' => 'alive']);
    // Age = 90 - TTL; just-written key has TTL ≈ 90 → age ≈ 0.
    expect($samples[0]->value)->toBeLessThanOrEqual(2.0);
    expect($samples[0]->value)->toBeGreaterThanOrEqual(0.0);
});

it('honours per-metric isEnabled toggles', function (): void {
    config()->set('queue-insights.prometheus.metrics.queue_depth', false);
    expect((new QueueDepthCollector())->isEnabled())->toBeFalse();

    config()->set('queue-insights.prometheus.metrics.queue_depth', true);
    expect((new QueueDepthCollector())->isEnabled())->toBeTrue();
});
