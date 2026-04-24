<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('reads live depth/inflight/delayed values, and returns 0/null when missing', function (): void {
    $r = Redis::connection('default');
    $r->command('setex', [KeyPrefix::make('live:depth:sqs:work'), 90, '42']);
    $r->command('setex', [KeyPrefix::make('live:inflight:sqs:work'), 90, '5']);

    $svc = resolve(QueueInsights::class);

    expect($svc->liveDepth('sqs', 'work'))->toBe(42)
        ->and($svc->liveInFlight('sqs', 'work'))->toBe(5)
        ->and($svc->liveDelayed('sqs', 'work'))->toBeNull()
        ->and($svc->liveDepth('sqs', 'missing'))->toBe(0);
});

it('returns the snapshot error message when set', function (): void {
    Redis::connection('default')->command('setex', [KeyPrefix::make('snapshot:error:sqs:work'), 600, 'throttled']);

    expect(resolve(QueueInsights::class)->snapshotError('sqs', 'work'))->toBe('throttled')
        ->and(resolve(QueueInsights::class)->snapshotError('sqs', 'clean'))->toBeNull();
});

it('returns lastSnapshotAt from the latest depth ZSET entry', function (): void {
    $ts = 1_700_000_000;
    Redis::connection('default')->command('zadd', [KeyPrefix::make('depth:sqs:work'), $ts, (string) $ts]);

    $at = resolve(QueueInsights::class)->lastSnapshotAt('sqs', 'work');

    expect($at)->toBeInstanceOf(Carbon::class)
        ->and($at->getTimestamp())->toBe($ts);
});

it('returns history entries from the last 24h', function (): void {
    $now = Date::now()->getTimestamp();
    $r = Redis::connection('default');
    $old = $now - (25 * 3600);
    $recent = $now - (3600);

    $r->command('zadd', [KeyPrefix::make('depth:sqs:work'), $old, '5']);
    $r->command('zadd', [KeyPrefix::make('depth:sqs:work'), $recent, '7']);

    $history = resolve(QueueInsights::class)->depthHistory('sqs', 'work');

    expect($history)->toHaveCount(1)
        ->toHaveKey($recent)
        ->and($history[$recent])->toBe(7);
});

it('lists configured queues from config', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
    ]);

    expect(resolve(QueueInsights::class)->configuredQueues())->toBe([
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
    ]);
});

it('returns job classes ordered by last seen (newest first)', function (): void {
    $now = Date::now()->getTimestamp();
    $r = Redis::connection('default');
    $r->command('zadd', [KeyPrefix::make('classes'), $now - 100, 'App\\Jobs\\Older']);
    $r->command('zadd', [KeyPrefix::make('classes'), $now, 'App\\Jobs\\Newer']);

    expect(resolve(QueueInsights::class)->jobClasses())->toBe(['App\\Jobs\\Newer', 'App\\Jobs\\Older']);
});

it('aggregates class metrics across 24 hourly buckets', function (): void {
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\Foo';

    // Seed 3 hourly processed buckets.
    $base = Date::now('UTC');
    foreach ([0, 1, 5] as $ago) {
        $bucket = $base->copy()->subHours($ago)->format('YmdH');
        $r->command('set', [KeyPrefix::make("processed:{$class}:{$bucket}"), '10']);
    }

    // One failure bucket.
    $r->command('set', [KeyPrefix::make('failed:' . $class . ':' . $base->format('YmdH')), '2']);

    // Duration hash.
    $durKey = KeyPrefix::make("duration:{$class}");
    $r->command('hset', [$durKey, 'count', '3']);
    $r->command('hset', [$durKey, 'sum_ms', '600']);
    $r->command('hset', [$durKey, 'max_ms', '500']);

    // Last run.
    $r->command('set', [KeyPrefix::make("last_run:{$class}"), '2026-04-24T12:00:00+00:00']);

    $metrics = resolve(QueueInsights::class)->classMetrics($class);

    expect($metrics->processed24h)->toBe(30)
        ->and($metrics->failed24h)->toBe(2)
        ->and($metrics->avgDurationMs)->toBe(200.0)
        ->and($metrics->maxDurationMs)->toBe(500)
        ->and($metrics->p95DurationMs)->toBeNull(); // no samples yet
    expect($metrics->lastRunAt)->toBeInstanceOf(Carbon::class);
});

it('returns empty class metrics for an unknown class', function (): void {
    $metrics = resolve(QueueInsights::class)->classMetrics('App\\Jobs\\NeverSeen');

    expect($metrics->processed24h)->toBe(0)
        ->and($metrics->failed24h)->toBe(0)
        ->and($metrics->avgDurationMs)->toBeNull()
        ->and($metrics->maxDurationMs)->toBeNull()
        ->and($metrics->p95DurationMs)->toBeNull()
        ->and($metrics->lastRunAt)->toBeNull();
});

it('computes p95 duration from the sample window', function (): void {
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\P95';
    $key = KeyPrefix::make("duration:samples:{$class}");

    // 100 samples of 1..100 ms. p95 index = ceil(0.95 * 100) - 1 = 94 → value 95.
    for ($i = 1; $i <= 100; ++$i) {
        $r->command('rpush', [$key, (string) $i]);
    }

    expect(resolve(QueueInsights::class)->p95DurationMs($class))->toBe(95);
});

it('returns null p95 when no samples exist', function (): void {
    expect(resolve(QueueInsights::class)->p95DurationMs('App\\Jobs\\Nothing'))->toBeNull();
});

it('reads recent completed entries from the global stream (newest first)', function (): void {
    $r = Redis::connection('default');
    $r->command('xadd', [KeyPrefix::make('completed'), ['class' => 'A'], '*']);
    $r->command('xadd', [KeyPrefix::make('completed'), ['class' => 'B'], '*']);
    $r->command('xadd', [KeyPrefix::make('completed'), ['class' => 'C'], '*']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['class'])->toBe('C')
        ->and($entries[2]['class'])->toBe('A');
});

it('reads recent completed entries scoped to a class', function (): void {
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\X';
    $r->command('xadd', [KeyPrefix::make("completed:{$class}"), ['queue' => 'a'], '*']);
    $r->command('xadd', [KeyPrefix::make("completed:{$class}"), ['queue' => 'b'], '*']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10, $class);

    expect($entries)->toHaveCount(2);
});
