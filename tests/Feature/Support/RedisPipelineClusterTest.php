<?php declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Support\EagerCommandCollector;
use SanderMuller\QueueInsights\Support\RedisPipeline;

// Connection-routing tests — driver-shape behaviour is covered against a
// real server in RedisPipelineTest. These assert the branch selection
// itself, so they mock the connection types and need no live Redis.

it('routes a clustered phpredis connection to eager per-command execution', function (): void {
    /** @var PhpRedisClusterConnection&MockInterface $cluster */
    $cluster = Mockery::mock(PhpRedisClusterConnection::class);
    // RedisCluster has no pipeline() — the cluster connection must never
    // be handed to the phpredis pipeline branch.
    $cluster->shouldNotReceive('pipeline');
    $cluster->shouldReceive('command')->once()->ordered()
        ->with('get', ['a'])->andReturn('1');
    $cluster->shouldReceive('command')->once()->ordered()
        ->with('zrange', ['z', 0, -1, ['withscores' => true]])->andReturn(['m' => '5']);

    $results = RedisPipeline::run($cluster, static function (mixed $pipe): void {
        $pipe->get('a');
        $pipe->zrange('z', 0, -1, ['withscores' => true]);
    });

    expect($results)->toBe(['1', ['m' => '5']]);
});

it('routes a clustered predis connection to eager per-command execution', function (): void {
    // The other arm of the cluster `instanceof` check — predis cluster
    // connections take the eager path too.
    /** @var PredisClusterConnection&MockInterface $cluster */
    $cluster = Mockery::mock(PredisClusterConnection::class);
    $cluster->shouldNotReceive('pipeline');
    $cluster->shouldReceive('command')->once()->with('get', ['a'])->andReturn('1');

    $results = RedisPipeline::run($cluster, static function (mixed $pipe): void {
        $pipe->get('a');
    });

    expect($results)->toBe(['1']);
});

it('keeps a plain phpredis connection on the native pipeline path', function (): void {
    /** @var PhpRedisConnection&MockInterface $plain */
    $plain = Mockery::mock(PhpRedisConnection::class);
    // A non-cluster phpredis connection must NOT be downgraded to eager
    // execution — it pipelines natively in one round-trip.
    $plain->shouldNotReceive('command');
    $plain->shouldReceive('pipeline')->once()->andReturn(['ok']);

    $results = RedisPipeline::run($plain, static function (mixed $pipe): void {
        $pipe->get('a');
    });

    expect($results)->toBe(['ok']);
});

it('EagerCommandCollector executes each queued command immediately, in order', function (): void {
    /** @var Connection&MockInterface $conn */
    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('command')->once()->ordered()
        ->with('get', ['a'])->andReturn('1');
    $conn->shouldReceive('command')->once()->ordered()
        ->with('hgetall', ['h'])->andReturn(['f' => '9']);

    $collector = new EagerCommandCollector($conn);
    $collector->get('a');
    $collector->hgetall('h');

    expect($collector->results())->toBe(['1', ['f' => '9']]);
});

it('EagerCommandCollector returns an empty list when nothing is queued', function (): void {
    /** @var Connection&MockInterface $conn */
    $conn = Mockery::mock(Connection::class);

    $collector = new EagerCommandCollector($conn);

    expect($collector->results())
        ->toBeEmpty();
});

// End-to-end coverage for the eager path on an actual clustered endpoint
// lives in tests/Feature/Cluster/ClusterSupportTest.php (the `cluster`
// group, gated on REDIS_CLUSTER_HOST).
