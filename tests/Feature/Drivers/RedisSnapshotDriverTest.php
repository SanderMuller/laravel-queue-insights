<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Drivers\RedisSnapshotDriver;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    // Simulate a Redis queue connection at queue.connections.redisqueue.connection = 'default'.
    config()->set('queue.connections.redisqueue', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
});

it('reports depth as LLEN queues:{name}', function (): void {
    $redis = Redis::connection('default');

    $redis->command('rpush', ['queues:work', 'a']);
    $redis->command('rpush', ['queues:work', 'b']);
    $redis->command('rpush', ['queues:work', 'c']);

    $driver = new RedisSnapshotDriver('redisqueue');

    expect($driver->depth('work'))->toBe(3);
});

it('reports in-flight as ZCARD queues:{name}:reserved', function (): void {
    $redis = Redis::connection('default');

    $redis->command('zadd', ['queues:work:reserved', 1700000000, 'job-1']);
    $redis->command('zadd', ['queues:work:reserved', 1700000001, 'job-2']);

    $driver = new RedisSnapshotDriver('redisqueue');

    expect($driver->inFlight('work'))->toBe(2);
});

it('reports delayed as ZCARD queues:{name}:delayed', function (): void {
    $redis = Redis::connection('default');

    $redis->command('zadd', ['queues:work:delayed', 1700000000, 'job-1']);

    $driver = new RedisSnapshotDriver('redisqueue');

    expect($driver->delayed('work'))->toBe(1);
});

it('returns zero counts when no queue keys exist', function (): void {
    $driver = new RedisSnapshotDriver('redisqueue');

    expect($driver->depth('empty'))->toBe(0)
        ->and($driver->inFlight('empty'))->toBe(0)
        ->and($driver->delayed('empty'))->toBe(0);
});

it('produces canonical keys matching the shared helper', function (): void {
    $driver = new RedisSnapshotDriver('redisqueue');

    expect($driver->canonicalKey('work'))->toBe('work')
        ->and($driver->canonicalKey('foo/bar'))->toBe('foo_bar');
});
