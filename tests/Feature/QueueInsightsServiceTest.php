<?php declare(strict_types=1);

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
    config()->set('queue-insights.horizon.autodiscover', false);

    expect(resolve(QueueInsights::class)->configuredQueues())->toBe([
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
    ]);
});

it('unions Horizon supervisor queues with static snapshots and dedups on canonical key', function (): void {
    config()->set('queue-insights.snapshots', [
        // Static entry — wins on collision.
        ['connection' => 'redis-staging', 'queue' => 'premium-broadcast'],
    ]);
    config()->set('queue-insights.horizon.autodiscover', true);
    config()->set('horizon.environments', [
        'testing' => [
            'staging-premiums' => [
                'connection' => 'redis-staging',
                // Duplicate of snapshots → dropped. Calculator is new → added.
                'queue' => 'premium-broadcast,premium-calculator',
            ],
            'staging-portefeuille' => [
                'connection' => 'redis-staging',
                'queue' => 'portefeuille',
            ],
        ],
    ]);

    expect(resolve(QueueInsights::class)->configuredQueues())->toBe([
        ['connection' => 'redis-staging', 'queue' => 'premium-broadcast'],
        ['connection' => 'redis-staging', 'queue' => 'premium-calculator'],
        ['connection' => 'redis-staging', 'queue' => 'portefeuille'],
    ]);
});

it('disables Horizon discovery when autodiscover is false', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    config()->set('queue-insights.horizon.autodiscover', false);
    config()->set('horizon.environments', [
        'testing' => ['sup' => ['connection' => 'redis-staging', 'queue' => 'horizon-only']],
    ]);

    expect(resolve(QueueInsights::class)->configuredQueues())->toBe([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
});

it('applies scopeConnection filter to the merged static + Horizon set', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'in-scope'],
        ['connection' => 'redis-staging', 'queue' => 'out-of-scope'],
    ]);
    config()->set('horizon.environments', [
        'testing' => [
            'sup' => ['connection' => 'sqs', 'queue' => 'from-horizon'],
            'sup2' => ['connection' => 'redis-staging', 'queue' => 'horizon-out'],
        ],
    ]);

    expect(resolve(QueueInsights::class)->configuredQueues('sqs'))->toBe([
        ['connection' => 'sqs', 'queue' => 'in-scope'],
        ['connection' => 'sqs', 'queue' => 'from-horizon'],
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
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'A']);
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'B']);
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'C']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['class'])->toBe('C')
        ->and($entries[2]['class'])->toBe('A');
});

it('reads recent completed entries scoped to a class', function (): void {
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\X';
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['queue' => 'a']);
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['queue' => 'b']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10, $class);

    expect($entries)->toHaveCount(2);
});

it('routes recentCompleted to the per-connection stream when only $connection is set', function (): void {
    $r = Redis::connection('default');
    // Aggregate stream — must NOT be read under scope (would leak the
    // imbalanced fixture's other-connection entries).
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'X', 'connection' => 'sqs']);
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'Y', 'connection' => 'sqs']);
    // Per-connection stream for redis — only this should be read.
    seedStream($r, KeyPrefix::make('completed:connection:redis'), ['class' => 'A', 'connection' => 'redis']);
    seedStream($r, KeyPrefix::make('completed:connection:redis'), ['class' => 'B', 'connection' => 'redis']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10, null, 'redis');

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['class'])->toBe('B')
        ->and($entries[1]['class'])->toBe('A');
});

it('class+connection drilldown reads the per-class stream and post-filters by connection', function (): void {
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\Drill';
    // Per-class stream carries entries for both connections (single class
    // can run on multiple connections in the wild).
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['connection' => 'redis', 'queue' => 'r1']);
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['connection' => 'sqs', 'queue' => 's1']);
    seedStream($r, KeyPrefix::make("completed:{$class}"), ['connection' => 'redis', 'queue' => 'r2']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10, $class, 'redis');

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['connection'])->toBe('redis')
        ->and($entries[1]['connection'])->toBe('redis');
});

it('class+connection drilldown surfaces scoped rows even when the class is hot on a foreign connection', function (): void {
    // Codex review #2 — without widening the read window, a class hot on
    // sqs would push redis rows out of the small `min($limit, 1000)`
    // slice and the scoped drilldown would silently return fewer than
    // $limit rows. Now reads the full per_class_stream_max window.
    $r = Redis::connection('default');
    $class = 'App\\Jobs\\Mixed';

    // 50 sqs entries first, then 5 redis entries on top (newer XADD ids).
    for ($i = 0; $i < 50; ++$i) {
        seedStream($r, KeyPrefix::make("completed:{$class}"), [
            'connection' => 'sqs',
            'queue' => 'work',
            'uuid' => 'sqs-' . $i,
        ]);
    }

    for ($i = 0; $i < 5; ++$i) {
        seedStream($r, KeyPrefix::make("completed:{$class}"), [
            'connection' => 'redis',
            'queue' => 'default',
            'uuid' => 'redis-' . $i,
        ]);
    }

    // Caller asks for 10; the read widens to 1000 so all 5 redis rows
    // make it past the post-filter.
    $entries = resolve(QueueInsights::class)->recentCompleted(10, $class, 'redis');

    expect($entries)->toHaveCount(5);
    foreach ($entries as $row) {
        expect($row['connection'])->toBe('redis');
    }
});

it('un-scoped recentCompleted still reads the aggregate stream', function (): void {
    $r = Redis::connection('default');
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'A', 'connection' => 'redis']);
    seedStream($r, KeyPrefix::make('completed'), ['class' => 'B', 'connection' => 'sqs']);

    $entries = resolve(QueueInsights::class)->recentCompleted(10);

    expect($entries)->toHaveCount(2);
});

it('hourlyThroughput returns a 24-bucket timeline of zeros by default', function (): void {
    $series = resolve(QueueInsights::class)->hourlyThroughput();

    expect($series)->toHaveCount(24)
        ->and($series[0])->toMatchArray(['processed' => 0, 'failed' => 0])
        ->and($series[23])->toMatchArray(['processed' => 0, 'failed' => 0]);
});

it('jobClasses($connection) reads the per-connection roster', function (): void {
    $r = Redis::connection('default');
    $now = Date::now('UTC')->getTimestamp();

    $r->command('zadd', [KeyPrefix::make('classes'), $now, 'App\\Global']);
    $r->command('zadd', [KeyPrefix::make('classes:redis'), $now, 'App\\OnRedis']);
    $r->command('zadd', [KeyPrefix::make('classes:sqs'), $now, 'App\\OnSqs']);

    expect(resolve(QueueInsights::class)->jobClasses())->toContain('App\\Global')
        ->and(resolve(QueueInsights::class)->jobClasses('redis'))->toBe(['App\\OnRedis'])
        ->and(resolve(QueueInsights::class)->jobClasses('sqs'))->toBe(['App\\OnSqs']);
});

it('classMetrics($class, $connection) reads per-connection bucket + duration + last_run keys', function (): void {
    $r = Redis::connection('default');
    $now = Date::now('UTC');
    $bucket = $now->format('YmdH');
    $class = 'App\\Jobs\\Foo';

    // Aggregate keys (existing API path).
    $r->command('set', [KeyPrefix::make("processed:{$class}:{$bucket}"), '20']);
    $r->command('set', [KeyPrefix::make("failed:{$class}:{$bucket}"), '4']);
    $r->command('hmset', [KeyPrefix::make("duration:{$class}"), ['count' => '10', 'sum_ms' => '1000', 'max_ms' => '500']]);
    $r->command('set', [KeyPrefix::make("last_run:{$class}"), $now->toIso8601String()]);

    // Per-connection keys (new Phase 4 path) — different totals so we can
    // distinguish which keys the read consulted.
    $r->command('set', [KeyPrefix::make("processed:{$class}:redis:{$bucket}"), '7']);
    $r->command('set', [KeyPrefix::make("failed:{$class}:redis:{$bucket}"), '1']);
    $r->command('hmset', [KeyPrefix::make("duration:{$class}:redis"), ['count' => '7', 'sum_ms' => '350', 'max_ms' => '120']]);
    $r->command('set', [KeyPrefix::make("last_run:{$class}:redis"), $now->toIso8601String()]);

    $aggregate = resolve(QueueInsights::class)->classMetrics($class);
    $scoped = resolve(QueueInsights::class)->classMetrics($class, 'redis');

    expect($aggregate->processed24h)->toBe(20)
        ->and($aggregate->failed24h)->toBe(4)
        ->and($aggregate->maxDurationMs)->toBe(500)
        ->and($scoped->processed24h)->toBe(7)
        ->and($scoped->failed24h)->toBe(1)
        ->and($scoped->maxDurationMs)->toBe(120);
});

it('hourlyThroughput($hours, $connection) reads per-connection processed+failed buckets', function (): void {
    $r = Redis::connection('default');
    $now = Date::now('UTC');
    $thisHour = $now->format('YmdH');

    // Per-connection roster + aggregate roster.
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\A']);
    $r->command('zadd', [KeyPrefix::make('classes:redis'), $now->getTimestamp(), 'App\\A']);
    $r->command('zadd', [KeyPrefix::make('classes:sqs'), $now->getTimestamp(), 'App\\A']);

    // Aggregate counters (sum=12), per-connection counters intentionally
    // smaller so the read path being consulted is unambiguous.
    $r->command('set', [KeyPrefix::make("processed:App\\A:{$thisHour}"), '12']);
    $r->command('set', [KeyPrefix::make("processed:App\\A:redis:{$thisHour}"), '5']);
    $r->command('set', [KeyPrefix::make("processed:App\\A:sqs:{$thisHour}"), '7']);

    $aggregate = resolve(QueueInsights::class)->hourlyThroughput();
    $scopedRedis = resolve(QueueInsights::class)->hourlyThroughput(24, 'redis');
    $scopedSqs = resolve(QueueInsights::class)->hourlyThroughput(24, 'sqs');

    expect($aggregate[23]['processed'])->toBe(12)
        ->and($scopedRedis[23]['processed'])->toBe(5)
        ->and($scopedSqs[23]['processed'])->toBe(7);
});

it('hourlyThroughput sums processed + failed counters across classes per hour bucket', function (): void {
    $r = Redis::connection('default');
    $now = Date::now('UTC');
    $thisHour = $now->format('YmdH');
    $lastHour = $now->copy()->subHour()->format('YmdH');

    // Two classes contributing to the current hour.
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\A']);
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\B']);
    $r->command('set', [KeyPrefix::make("processed:App\\A:{$thisHour}"), '5']);
    $r->command('set', [KeyPrefix::make("processed:App\\B:{$thisHour}"), '3']);
    $r->command('set', [KeyPrefix::make("failed:App\\A:{$thisHour}"), '1']);

    // One class contributing to the previous hour.
    $r->command('set', [KeyPrefix::make("processed:App\\A:{$lastHour}"), '10']);

    $series = resolve(QueueInsights::class)->hourlyThroughput();

    expect($series)->toHaveCount(24);

    // Oldest first → current hour is last, previous hour second-to-last.
    expect($series[23])->toMatchArray(['processed' => 8, 'failed' => 1])
        ->and($series[22])->toMatchArray(['processed' => 10, 'failed' => 0]);
});

it('hourlyThroughput excludes silenced classes from the failed series but keeps processed exact', function (): void {
    config()->set('queue-insights.silenced', ['App\\Loud']);
    app()->forgetScopedInstances();

    $r = Redis::connection('default');
    $now = Date::now('UTC');
    $thisHour = $now->format('YmdH');

    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\Loud']);
    $r->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), 'App\\Quiet']);
    $r->command('set', [KeyPrefix::make("processed:App\\Loud:{$thisHour}"), '7']);
    $r->command('set', [KeyPrefix::make("processed:App\\Quiet:{$thisHour}"), '4']);
    $r->command('set', [KeyPrefix::make("failed:App\\Loud:{$thisHour}"), '50']);
    $r->command('set', [KeyPrefix::make("failed:App\\Quiet:{$thisHour}"), '2']);

    $series = resolve(QueueInsights::class)->hourlyThroughput();

    // Processed sums BOTH classes (silencing applies to failure noise only).
    // Failed sums only the non-silenced class — the 50 noisy failures drop.
    expect($series[23])->toMatchArray([
        'processed' => 11,
        'failed' => 2,
    ]);
});

it('configuredQueues dedups when snapshots and Horizon collide only after alias canonicalisation', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'shared-queue'],
    ]);
    config()->set('queue-insights.horizon.autodiscover', true);
    config()->set('horizon.environments', [
        'testing' => ['sup' => ['connection' => 'redis-staging', 'queue' => 'shared-queue']],
    ]);

    // Snapshots wins on collision; only one entry survives even though both
    // raw connection names are different. ConfiguredQueueList::push canonicalises
    // 'redis' -> 'redis-staging' before the dedup-seen check.
    expect(resolve(QueueInsights::class)->configuredQueues())->toBe([
        ['connection' => 'redis-staging', 'queue' => 'shared-queue'],
    ]);
});
