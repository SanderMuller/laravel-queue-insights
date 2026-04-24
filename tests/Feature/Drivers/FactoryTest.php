<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Drivers\DatabaseSnapshotDriver;
use SanderMuller\QueueInsights\Drivers\NullSnapshotDriver;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Drivers\RedisSnapshotDriver;
use SanderMuller\QueueInsights\Drivers\SqsSnapshotDriver;

it('resolves RedisSnapshotDriver for redis queue connections', function (): void {
    config()->set('queue.connections.rq', ['driver' => 'redis']);

    expect((new QueueSnapshotDriverFactory())->make('rq'))->toBeInstanceOf(RedisSnapshotDriver::class);
});

it('resolves DatabaseSnapshotDriver for database queue connections', function (): void {
    config()->set('queue.connections.dbq', ['driver' => 'database']);

    expect((new QueueSnapshotDriverFactory())->make('dbq'))->toBeInstanceOf(DatabaseSnapshotDriver::class);
});

it('resolves SqsSnapshotDriver for sqs queue connections', function (): void {
    config()->set('queue.connections.sqsq', [
        'driver' => 'sqs',
        'region' => 'eu-west-1',
    ]);

    expect((new QueueSnapshotDriverFactory())->make('sqsq'))->toBeInstanceOf(SqsSnapshotDriver::class);
});

it('resolves NullSnapshotDriver for the sync driver', function (): void {
    config()->set('queue.connections.syncq', ['driver' => 'sync']);

    expect((new QueueSnapshotDriverFactory())->make('syncq'))->toBeInstanceOf(NullSnapshotDriver::class);
});

it('falls back to NullSnapshotDriver with a warning for unknown drivers', function (): void {
    config()->set('queue.connections.weird', ['driver' => 'beanstalkd']);

    Log::shouldReceive('warning')
        ->once()
        ->with('queue-insights: unknown queue driver; using NullSnapshotDriver', Mockery::any());

    expect((new QueueSnapshotDriverFactory())->make('weird'))->toBeInstanceOf(NullSnapshotDriver::class);
});

it('honors a driver_override by driver name', function (): void {
    config()->set('queue.connections.custom', ['driver' => 'beanstalkd']);
    config()->set('queue-insights.driver_overrides.custom', 'redis');

    expect((new QueueSnapshotDriverFactory())->make('custom'))->toBeInstanceOf(RedisSnapshotDriver::class);
});

it('honors a driver_override that is a Closure returning a QueueSnapshotDriver', function (): void {
    $custom = new NullSnapshotDriver();

    config()->set('queue-insights.driver_overrides.anything', fn (): QueueSnapshotDriver => $custom);

    expect((new QueueSnapshotDriverFactory())->make('anything'))->toBe($custom);
});

it('honors a driver_override that is a class-string implementing the contract', function (): void {
    config()->set('queue-insights.driver_overrides.viaclass', NullSnapshotDriver::class);

    expect((new QueueSnapshotDriverFactory())->make('viaclass'))->toBeInstanceOf(NullSnapshotDriver::class);
});
