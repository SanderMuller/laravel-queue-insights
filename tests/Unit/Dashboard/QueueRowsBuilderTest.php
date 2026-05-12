<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Dashboard\QueueRowsBuilder;
use SanderMuller\QueueInsights\QueueInsights;

// Routed through the container so PHPStan accepts the
// `final readonly QueueInsights` arg as a Mockery mock — see
// tests/Unit/Dashboard/ModalResolverTest.php for the same pattern.
function queueRowsBuilder((LegacyMockInterface&MockInterface)|null $svc = null): QueueRowsBuilder
{
    $svc ??= Mockery::mock(QueueInsights::class);
    app()->instance(QueueInsights::class, $svc);

    return resolve(QueueRowsBuilder::class);
}

beforeEach(function (): void {
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue.connections.redis', ['driver' => 'redis']);
});

/**
 * @param  array{depth?: int, delayed?: ?int, inflight?: ?int, error?: ?string, last_at?: ?\Carbon\CarbonInterface, pending_tracked_count?: ?int}  $overrides
 * @return array{depth: int, delayed: ?int, inflight: ?int, error: ?string, last_at: ?\Carbon\CarbonInterface, pending_tracked_count: ?int}
 */
function snapshot(array $overrides = []): array
{
    return array_merge([
        'depth' => 0,
        'delayed' => 0,
        'inflight' => 0,
        'error' => null,
        'last_at' => Date::now(),
        'pending_tracked_count' => 0,
    ], $overrides);
}

it('build returns one row per configured queue with live metrics + snapshot state', function (): void {
    Date::setTestNow('2026-04-28 10:00:00');

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->once()->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->once()->andReturn([
        snapshot(['depth' => 50, 'inflight' => 3, 'delayed' => 2, 'last_at' => Date::now()->subSeconds(30), 'pending_tracked_count' => 52]),
    ]);
    $svc->shouldReceive('queueWaitPercentiles')->with('redis', 'default')->andReturn(['p50' => 120, 'p95' => 540]);

    $rows = queueRowsBuilder($svc)->build('');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['connection'])->toBe('redis')
        ->and($rows[0]['queue'])->toBe('default')
        ->and($rows[0]['driver'])->toBe('redis')
        ->and($rows[0]['depth'])->toBe(50)
        ->and($rows[0]['inflight'])->toBe(3)
        ->and($rows[0]['delayed'])->toBe(2)
        ->and($rows[0]['stale'])->toBeFalse()
        ->and($rows[0]['wait_p50_ms'])->toBe(120)
        ->and($rows[0]['wait_p95_ms'])->toBe(540)
        ->and($rows[0]['inspector_open'])->toBeFalse()
        ->and($rows[0]['inspector_disabled'])->toBeFalse()
        ->and($rows[0]['tracked_count'])->toBe(52)
        ->and($rows[0]['pending_gap'])->toBe(0)
        ->and($rows[0]['pending_jobs'])->toBeEmpty()
        ->and($rows[0]['delayed_jobs'])->toBeEmpty();

    Date::setTestNow();
});

it('build flags rows older than 120s as stale', function (): void {
    Date::setTestNow('2026-04-28 10:00:00');

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(['last_at' => Date::now()->subSeconds(180)])]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    expect(queueRowsBuilder($svc)->build('')[0]['stale'])->toBeTrue();

    Date::setTestNow();
});

it('build flags rows with a null lastSnapshotAt as stale', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(['last_at' => null])]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    expect(queueRowsBuilder($svc)->build('')[0]['stale'])->toBeTrue();
});

it('build skips configured entries whose queue name fails canonicalisation', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => ''], // empty queue name → InvalidArgumentException from CanonicalQueueKey::from
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot()]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    $rows = queueRowsBuilder($svc)->build('');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['queue'])->toBe('default');
});

it('build expands the inspector for the matching key and loads the per-queue job lists', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(['depth' => 10, 'pending_tracked_count' => 10])]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);
    $pendingList = [['uuid' => 'p1'], ['uuid' => 'p2']];
    $delayedList = [['uuid' => 'd1']];
    $svc->shouldReceive('pendingJobs')->with('redis', 'default')->once()->andReturn($pendingList);
    $svc->shouldReceive('delayedJobs')->with('redis', 'default')->once()->andReturn($delayedList);

    $rows = queueRowsBuilder($svc)->build('redis:default');

    expect($rows[0]['inspector_open'])->toBeTrue()
        ->and($rows[0]['pending_jobs'])->toBe($pendingList)
        ->and($rows[0]['delayed_jobs'])->toBe($delayedList);
});

it('build short-circuits inspector fields when pending tracking is disabled', function (): void {
    config()->set('queue-insights.pending.enabled', false);

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(['depth' => 10, 'pending_tracked_count' => null])]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);
    $svc->shouldNotReceive('pendingJobs');
    $svc->shouldNotReceive('delayedJobs');

    $rows = queueRowsBuilder($svc)->build('redis:default');

    expect($rows[0]['inspector_disabled'])->toBeTrue()
        ->and($rows[0]['inspector_open'])->toBeFalse()
        ->and($rows[0]['tracked_count'])->toBe(0)
        ->and($rows[0]['pending_gap'])->toBe(0);
});

it('build computes pending_gap from |tracked - (depth + delayed)|', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(['depth' => 50, 'delayed' => 10, 'pending_tracked_count' => 45])]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    expect(queueRowsBuilder($svc)->build('')[0]['pending_gap'])->toBe(15);
});

it('build restricts iteration to a scoped connection when one is provided', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);

    $svc = Mockery::mock(QueueInsights::class);
    // The scope is threaded into configuredQueues itself — the builder
    // doesn't do its own scope filter. The mock returns only the matching
    // row for the scoped call (mirroring the production behaviour).
    $svc->shouldReceive('configuredQueues')->with('sqs')->once()->andReturn([
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->once()->andReturn([snapshot()]);
    $svc->shouldReceive('queueWaitPercentiles')->with('sqs', 'work')->once()->andReturn(['p50' => null, 'p95' => null]);

    $rows = queueRowsBuilder($svc)->build('', 'sqs');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['connection'])->toBe('sqs')
        ->and($rows[0]['queue'])->toBe('work');
});

it('build returns every row when scope is null (back-compat)', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot(), snapshot()]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    $rows = queueRowsBuilder($svc)->build('');

    expect($rows)->toHaveCount(2);
});

it('build falls back to em-dash for a non-string driver config', function (): void {
    config()->set('queue.connections.redis', ['driver' => null]);

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('configuredQueues')->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    $svc->shouldReceive('queueRowSnapshots')->andReturn([snapshot()]);
    $svc->shouldReceive('queueWaitPercentiles')->andReturn(['p50' => null, 'p95' => null]);

    expect(queueRowsBuilder($svc)->build('')[0]['driver'])->toBe('—');
});
