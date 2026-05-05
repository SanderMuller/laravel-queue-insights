<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Dashboard\ClassRowsBuilder;
use SanderMuller\QueueInsights\DTO\JobClassMetrics;
use SanderMuller\QueueInsights\QueueInsights;

// Routed through the container (instance binding) so PHPStan accepts
// the `final readonly QueueInsights` arg as a Mockery mock — see
// tests/Unit/Dashboard/ModalResolverTest.php for the same pattern.
function classRowsBuilder((LegacyMockInterface&MockInterface)|null $svc = null): ClassRowsBuilder
{
    $svc ??= Mockery::mock(QueueInsights::class);
    app()->instance(QueueInsights::class, $svc);

    return resolve(ClassRowsBuilder::class);
}

it('build returns one row per registered class with metrics flattened to scalars', function (): void {
    $now = Date::parse('2026-04-28 10:00:00');

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('jobClasses')->with(null)->once()->andReturn(['App\\Jobs\\Alpha', 'App\\Jobs\\Beta']);
    $svc->shouldReceive('classMetrics')->with('App\\Jobs\\Alpha', null)->once()->andReturn(
        new JobClassMetrics('App\\Jobs\\Alpha', 100, 2, 312.5, 1200, 820, $now),
    );
    $svc->shouldReceive('classMetrics')->with('App\\Jobs\\Beta', null)->once()->andReturn(
        new JobClassMetrics('App\\Jobs\\Beta', 50, 0, 90.0, 200, 150, null),
    );

    $rows = classRowsBuilder($svc)->build();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'class' => 'App\\Jobs\\Alpha',
            'processed_24h' => 100,
            'failed_24h' => 2,
            'avg_ms' => 312.5,
            'p95_ms' => 820,
            'max_ms' => 1200,
            'last_run_at' => $now,
            'silenced' => false,
        ])
        ->and($rows[1]['class'])->toBe('App\\Jobs\\Beta')
        ->and($rows[1]['last_run_at'])->toBeNull()
        ->and($rows[1]['silenced'])->toBeFalse();
});

it('build flags only matching classes as silenced', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Beta']);
    app()->forgetScopedInstances();

    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('jobClasses')->with(null)->once()->andReturn(['App\\Jobs\\Alpha', 'App\\Jobs\\Beta']);
    $svc->shouldReceive('classMetrics')->with('App\\Jobs\\Alpha', null)->once()->andReturn(
        new JobClassMetrics('App\\Jobs\\Alpha', 1, 0, 1.0, 1, 1, null),
    );
    $svc->shouldReceive('classMetrics')->with('App\\Jobs\\Beta', null)->once()->andReturn(
        new JobClassMetrics('App\\Jobs\\Beta', 1, 0, 1.0, 1, 1, null),
    );

    $rows = classRowsBuilder($svc)->build();

    expect($rows[0]['silenced'])->toBeFalse()
        ->and($rows[1]['silenced'])->toBeTrue();
});

it('build returns an empty list when no classes are registered', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('jobClasses')->with(null)->once()->andReturn([]);
    $svc->shouldNotReceive('classMetrics');

    expect(classRowsBuilder($svc)->build())->toBeEmpty();
});

it('build threads scopeConnection through jobClasses + classMetrics for per-connection rows', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('jobClasses')->with('redis')->once()->andReturn(['App\\Jobs\\Alpha']);
    $svc->shouldReceive('classMetrics')->with('App\\Jobs\\Alpha', 'redis')->once()->andReturn(
        new JobClassMetrics('App\\Jobs\\Alpha', 10, 0, 50.0, 100, 80, null),
    );

    $rows = classRowsBuilder($svc)->build('redis');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['class'])->toBe('App\\Jobs\\Alpha')
        ->and($rows[0]['processed_24h'])->toBe(10);
});
