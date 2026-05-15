<?php declare(strict_types=1);

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Support\HorizonNotRunning;
use SanderMuller\QueueInsights\Tests\Fixtures\FakeHorizonServiceProvider;

beforeEach(function (): void {
    config()->set('queue-insights.horizon.autodiscover', true);
    config()->set('queue-insights.horizon.environment');
    config()->set('horizon.defaults', []);
    // A supervisor matched by the test env so `discover()` returns non-empty
    // — required for `isNotRunning()` to even reach the master-repo check.
    config()->set('horizon.environments', [
        'testing' => [
            'sup' => ['connection' => 'redis', 'queue' => 'default'],
        ],
    ]);
});

/**
 * Bind a stub MasterSupervisorRepository. Mockery doesn't call the constructor,
 * so no Horizon services are needed — just a contract impl that returns the
 * desired `all()` shape.
 *
 * @param  list<mixed>  $masters
 */
function bindMasterRepo(array $masters): void
{
    /** @var MasterSupervisorRepository&MockInterface $repo */
    $repo = Mockery::mock(MasterSupervisorRepository::class);
    $repo->shouldReceive('all')->andReturn($masters);
    app()->instance(MasterSupervisorRepository::class, $repo);
}

it("isNotRunning is false when Horizon's service provider is not registered", function (): void {
    // Vapor / dont-discover case — provider isn't loaded → not the runtime
    // here, so "not running" isn't a problem to surface.
    expect((new HorizonNotRunning())->isNotRunning())->toBeFalse();
});

it('isNotRunning is false when no supervisors are configured for this env', function (): void {
    config()->set('horizon.environments', []); // discover() returns []
    app()->register(FakeHorizonServiceProvider::class);

    expect((new HorizonNotRunning())->isNotRunning())->toBeFalse();
});

it('isNotRunning is false when a master supervisor is alive', function (): void {
    app()->register(FakeHorizonServiceProvider::class);
    bindMasterRepo([['name' => 'host-1']]);

    expect((new HorizonNotRunning())->isNotRunning())->toBeFalse();
});

it('isNotRunning is true when the provider is loaded + supervisors configured + no master alive', function (): void {
    // The actionable "you forgot to start Horizon" state.
    app()->register(FakeHorizonServiceProvider::class);
    bindMasterRepo([]); // empty = no master in Horizon's 14s window

    expect((new HorizonNotRunning())->isNotRunning())->toBeTrue();
});

it('isNotRunning is false when the MasterSupervisorRepository throws', function (): void {
    // Defensive: Redis down / horizon.use misconfigured → don't banner. Same
    // conservative stance SnapshotWatchdog takes on a Redis fault.
    app()->register(FakeHorizonServiceProvider::class);
    /** @var MasterSupervisorRepository&MockInterface $repo */
    $repo = Mockery::mock(MasterSupervisorRepository::class);
    $repo->shouldReceive('all')->andThrow(new RuntimeException('redis down'));
    app()->instance(MasterSupervisorRepository::class, $repo);

    expect((new HorizonNotRunning())->isNotRunning())->toBeFalse();
});
