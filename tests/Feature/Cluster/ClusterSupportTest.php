<?php declare(strict_types=1);

use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use SanderMuller\QueueInsights\Support\BatchItemMeta;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\RedisPipeline;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

// These tests prove the package's multi-key surfaces — Lua scripts,
// pipelines, RENAME, and MGET fan-outs — work against a real cluster-mode
// Redis once `redis_cluster` hash-tag pinning is on. They run only on the
// `cluster` CI lane (or a local cluster): REDIS_CLUSTER_HOST must be set
// and reachable. On the normal matrix lanes — which never export it —
// every test here skips.
//
// Multi-key teardown (`del` of several keys at once) doubles as extra
// CROSSSLOT coverage: it only works because the hash tag co-locates them.
beforeEach(function (): void {
    if (! RedisAvailability::clusterAvailable()) {
        $this->markTestSkipped('redis cluster not available — set REDIS_CLUSTER_HOST');
    }

    // Pin every package key to one slot via the hash-tagged prefix — the
    // whole reason `redis_cluster` exists. Without this, the multi-key Lua
    // below would fail with CROSSSLOT.
    config()->set('queue-insights.redis_cluster', true);
    config()->set('queue-insights.key_prefix', 'qmcluster:');
    config()->set('queue-insights.connection_aliases', []);
});

it('resolves the cluster connection as a genuine cluster connection type', function (): void {
    $conn = R::conn('cluster');

    // Guards against the CI lane silently pointing at a standalone Redis —
    // a non-cluster connection has no CROSSSLOT, so the multi-key tests
    // below would pass trivially and prove nothing.
    expect($conn instanceof PhpRedisClusterConnection || $conn instanceof PredisClusterConnection)
        ->toBeTrue();
})->group('cluster');

it('leaves the default connection non-cluster even when the cluster connection is wired up', function (): void {
    // TestCase sets the cluster-mode option under the `clusters`-scoped
    // config subtree, not the global one — so `default` must still resolve
    // to a plain connection. Regression guard against a global config leak.
    $default = R::conn('default');

    expect($default)->not->toBeInstanceOf(PhpRedisClusterConnection::class)
        ->and($default)->not->toBeInstanceOf(PredisClusterConnection::class);
})->group('cluster');

it('runs a 2-key Lua script (SetexPair) without CROSSSLOT when keys share the hash tag', function (): void {
    $redis = R::conn('cluster');

    // Both keys flow through KeyPrefix::make(), which — with redis_cluster
    // on — wraps them in the same `{qmcluster:}` tag → same slot.
    $aggregate = KeyPrefix::make('last_run:App\\Jobs\\Demo');
    $perConn = KeyPrefix::make('last_run:App\\Jobs\\Demo:redis');

    RedisEval::exec(
        $redis,
        LuaScripts::setexPair(),
        2,
        $aggregate,
        $perConn,
        '3600',
        '2026-05-14T00:00:00Z',
    );

    expect($redis->command('get', [$aggregate]))->toBe('2026-05-14T00:00:00Z')
        ->and($redis->command('get', [$perConn]))->toBe('2026-05-14T00:00:00Z');

    $redis->command('del', [$aggregate, $perConn]);
})->group('cluster');

it('runs a 3-key Lua script (MarkInFlight) without CROSSSLOT', function (): void {
    $redis = R::conn('cluster');

    $hash = KeyPrefix::make('pending:uuid-1');
    $pendingZset = KeyPrefix::make('pending-zset:redis:default');
    $inflightZset = KeyPrefix::make('inflight-zset:redis:default');

    // Seed the pending side so the pending → in-flight transition has
    // something to move.
    $redis->command('hset', [$hash, 'class', 'App\\Jobs\\Demo']);
    $redis->command('zadd', [$pendingZset, 100, 'uuid-1']);

    RedisEval::exec(
        $redis,
        LuaScripts::markInFlight(),
        3,
        $hash,
        $pendingZset,
        $inflightZset,
        'uuid-1',
        '200',
        '86400',
        '1',
    );

    // ZREM from pending + ZADD to in-flight both landed atomically.
    expect($redis->command('zrange', [$pendingZset, 0, -1]))
        ->toBeEmpty()
        ->and($redis->command('zrange', [$inflightZset, 0, -1]))->toBe(['uuid-1']);

    $redis->command('del', [$hash, $pendingZset, $inflightZset]);
})->group('cluster');

it('runs a pipelined multi-key read through the eager cluster fallback', function (): void {
    $redis = R::conn('cluster');

    $a = KeyPrefix::make('cluster-pipe:a');
    $b = KeyPrefix::make('cluster-pipe:b');
    $redis->command('set', [$a, '10']);
    $redis->command('set', [$b, '20']);

    // RedisPipeline::run routes a cluster connection through
    // EagerCommandCollector — RedisCluster has no pipeline().
    $results = RedisPipeline::run($redis, static function (mixed $pipe) use ($a, $b): void {
        $pipe->get($a);
        $pipe->get($b);
    });

    expect($results)->toBe(['10', '20']);

    $redis->command('del', [$a, $b]);
})->group('cluster');

it('runs a multi-key RENAME without CROSSSLOT (the purge-command pattern)', function (): void {
    $redis = R::conn('cluster');

    // QueueInsightsPurgePendingCommand RENAMEs a zset to a temp key built
    // as `$zsetKey . ':purging-…'` — both carry the hash-tagged prefix, so
    // they co-locate onto one slot. phpredis's RedisCluster honours that
    // and runs the RENAME; predis's cluster client rejects RENAME outright
    // regardless of slots (see the skip below).
    $zsetKey = KeyPrefix::make('pending-zset:redis:default');
    $tempKey = $zsetKey . ':purging-abc123';
    $redis->command('zadd', [$zsetKey, 1, 'uuid-1']);

    $redis->command('rename', [$zsetKey, $tempKey]);

    expect($redis->command('exists', [$zsetKey]))->toBe(0)
        ->and($redis->command('zrange', [$tempKey, 0, -1]))->toBe(['uuid-1']);

    $redis->command('del', [$tempKey]);
})->group('cluster')->skip(
    getenv('QI_REDIS_CLIENT') === 'predis',
    'predis cluster rejects RENAME outright (NotSupportedException), even for same-slot keys. '
    . 'QueueInsightsPurgePendingCommand catches this and fails gracefully — see .ai/docs/redis-cluster.md.',
);

it('BatchItemMeta::loadCompleted bulk-fetches stream entries via a single Lua EVAL on cluster', function (): void {
    $redis = R::conn('cluster');

    // Seed two completed-stream entries on the cluster connection. The
    // EVAL inside BatchItemMeta::loadCompleted touches one stream key with
    // many ARGV ids — single-slot via the hash-tagged prefix, so it must
    // round-trip exactly once even on cluster. Previously this path
    // pipelined N XRANGEs and silently fanned out via
    // EagerCommandCollector — proven cluster-safe here.
    $streamKey = KeyPrefix::make('completed');
    // phpredis cluster requires field/value pairs as an associative array (3rd
    // arg) and Predis cluster's XADD positional arity differs from non-cluster.
    // Route through Lua so a single seed path works on both — same pattern
    // production uses (RecordJobProcessed::xaddApprox).
    $xaddScript = "return redis.call('XADD', KEYS[1], '*', unpack(ARGV))";
    $id1 = RedisEval::exec($redis, $xaddScript, 1, $streamKey, 'class', 'App\\Jobs\\Foo', 'attempts', '1');
    $id2 = RedisEval::exec($redis, $xaddScript, 1, $streamKey, 'class', 'App\\Jobs\\Bar', 'attempts', '2');

    expect($id1)->toBeString()->not->toBeEmpty()
        ->and($id2)->toBeString()->not->toBeEmpty();
    assert(is_string($id1) && is_string($id2));

    // Route through the cluster connection by swapping the package's
    // default to the cluster name for the duration of this assertion —
    // BatchItemMeta::loadCompleted accepts the connection directly so we
    // pass it in without bouncing through config.
    $meta = BatchItemMeta::loadCompleted($redis, [
        'uuid-1' => $id1,
        'uuid-2' => $id2,
    ]);

    expect($meta)->toHaveKey($id1)
        ->and($meta[$id1]['class'])->toBe('App\\Jobs\\Foo')
        ->and($meta[$id1]['attempts'])->toBe(1)
        ->and($meta)->toHaveKey($id2)
        ->and($meta[$id2]['class'])->toBe('App\\Jobs\\Bar')
        ->and($meta[$id2]['attempts'])->toBe(2);

    $redis->command('del', [$streamKey]);
})->group('cluster');

it('runs a multi-key MGET across KeyPrefix-built keys without CROSSSLOT', function (): void {
    $redis = R::conn('cluster');

    // Eight production read paths (WaitTimeMetrics, BatchReader, RowEnricher,
    // ParentClassResolver, …) issue a raw MGET across a list of per-uuid
    // keys. Every such key flows through KeyPrefix::make(), so the hash tag
    // co-locates the whole batch onto one slot — CROSSSLOT-legal. All three
    // keys are set so the assertion doesn't depend on the driver's
    // missing-key shape (phpredis `false` vs Predis `null`).
    $keys = [
        KeyPrefix::make('wait:uuid-1'),
        KeyPrefix::make('wait:uuid-2'),
        KeyPrefix::make('wait:uuid-3'),
    ];
    $redis->command('set', [$keys[0], '11']);
    $redis->command('set', [$keys[1], '22']);
    $redis->command('set', [$keys[2], '33']);

    // phpredis mGet() / Predis both take a single array argument.
    $values = $redis->command('mget', [$keys]);

    expect(array_values((array) $values))->toBe(['11', '22', '33']);

    $redis->command('del', $keys);
})->group('cluster');
