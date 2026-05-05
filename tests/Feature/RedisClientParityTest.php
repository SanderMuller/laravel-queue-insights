<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

/**
 * Regression suite: every Redis op in the hot path must work identically on phpredis and
 * Predis. Reported by the hihaho peer — `SET key val EX ttl`, `MGET key1 key2 …`, `EVAL`,
 * `XADD ... MAXLEN ~`, and `XREVRANGE … COUNT N` all have divergent signatures between the
 * ext-redis extension and Predis. Run this file under both drivers via QI_REDIS_CLIENT.
 */
beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('XREVRANGE COUNT works without the literal COUNT token', function (): void {
    $redis = Redis::connection('default');

    // Seed 3 entries via the same RedisEval path production uses.
    RedisEval::exec($redis, "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1])", 1, KeyPrefix::make('completed'), 'A');
    RedisEval::exec($redis, "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1])", 1, KeyPrefix::make('completed'), 'B');
    RedisEval::exec($redis, "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1])", 1, KeyPrefix::make('completed'), 'C');

    $entries = resolve(QueueInsights::class)->recentCompleted(10);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['class'])->toBe('C');
});

it('MGET accepts a single-array form that unwraps on Predis and passes through on phpredis', function (): void {
    $redis = Redis::connection('default');
    $redis->command('set', [KeyPrefix::make('a'), '1']);
    $redis->command('set', [KeyPrefix::make('b'), '2']);
    $redis->command('set', [KeyPrefix::make('c'), '3']);

    $values = $redis->command('mget', [[KeyPrefix::make('a'), KeyPrefix::make('b'), KeyPrefix::make('c')]]);

    expect($values)->toBe(['1', '2', '3']);
});

it('RedisEval::exec returns script values across drivers', function (): void {
    $redis = Redis::connection('default');

    $result = RedisEval::exec($redis, 'return tonumber(ARGV[1]) + tonumber(ARGV[2])', 0, '2', '3');

    expect(is_numeric($result) ? (int) $result : null)->toBe(5);
});

it('RedisEval::exec handles KEYS + ARGV correctly across drivers', function (): void {
    $redis = Redis::connection('default');

    RedisEval::exec(
        $redis,
        "redis.call('SET', KEYS[1], ARGV[1]); return 1",
        1,
        KeyPrefix::make('evalprobe'),
        'hello-world',
    );

    expect($redis->command('get', [KeyPrefix::make('evalprobe')]))->toBe('hello-world');
});

it('IncrPairWithExpire.lua bumps both counters and stamps EXPIREAT atomically', function (): void {
    $redis = Redis::connection('default');
    $expireAt = Date::now()
        ->getTimestamp() + 7 * 86400;

    RedisEval::exec(
        $redis,
        LuaScripts::incrPairWithExpire(),
        2,
        KeyPrefix::make('processed:App\\Foo:2026050412'),
        KeyPrefix::make('processed:App\\Foo:redis:2026050412'),
        (string) $expireAt,
    );

    expect(R::int('get', 'qmtest:processed:App\\Foo:2026050412'))->toBe(1)
        ->and(R::int('get', 'qmtest:processed:App\\Foo:redis:2026050412'))->toBe(1);

    $aggTtl = R::int('ttl', 'qmtest:processed:App\\Foo:2026050412');
    $perTtl = R::int('ttl', 'qmtest:processed:App\\Foo:redis:2026050412');
    expect($aggTtl)->toBeGreaterThan(7 * 86400 - 60)->toBeLessThanOrEqual(7 * 86400)
        ->and($perTtl)->toBeGreaterThan(7 * 86400 - 60)
        ->toBeLessThanOrEqual(7 * 86400);
});

it('DurationPair.lua HINCRBYs count + sum_ms and CASes max_ms across both keys', function (): void {
    $redis = Redis::connection('default');

    RedisEval::exec($redis, LuaScripts::durationPair(), 2,
        KeyPrefix::make('duration:App\\Foo'), KeyPrefix::make('duration:App\\Foo:redis'),
        '120', (string) 2592000);
    RedisEval::exec($redis, LuaScripts::durationPair(), 2,
        KeyPrefix::make('duration:App\\Foo'), KeyPrefix::make('duration:App\\Foo:redis'),
        '80', (string) 2592000);
    // Lower duration must NOT replace the running max.
    RedisEval::exec($redis, LuaScripts::durationPair(), 2,
        KeyPrefix::make('duration:App\\Foo'), KeyPrefix::make('duration:App\\Foo:redis'),
        '50', (string) 2592000);

    foreach (['duration:App\\Foo', 'duration:App\\Foo:redis'] as $k) {
        expect(R::int('hget', 'qmtest:' . $k, 'count'))->toBe(3)
            ->and((float) R::str('hget', 'qmtest:' . $k, 'sum_ms'))->toBe(250.0)
            ->and(R::int('hget', 'qmtest:' . $k, 'max_ms'))->toBe(120);
    }
});

it('SamplesPair.lua RPUSHes + LTRIMs both lists to the cap', function (): void {
    $redis = Redis::connection('default');

    for ($i = 1; $i <= 7; ++$i) {
        RedisEval::exec($redis, LuaScripts::samplesPair(), 2,
            KeyPrefix::make('duration:samples:App\\Foo'),
            KeyPrefix::make('duration:samples:App\\Foo:redis'),
            (string) ($i * 10), '5', (string) 2592000);
    }

    // Cap is 5; oldest two entries (10, 20) must be evicted, newest 5 retained.
    foreach (['duration:samples:App\\Foo', 'duration:samples:App\\Foo:redis'] as $k) {
        $entries = R::raw('lrange', 'qmtest:' . $k, 0, -1);
        expect($entries)->toBe(['30', '40', '50', '60', '70']);
    }
});

it('SetexPair.lua writes both keys with the requested TTL', function (): void {
    $redis = Redis::connection('default');

    RedisEval::exec($redis, LuaScripts::setexPair(), 2,
        KeyPrefix::make('last_run:App\\Foo'),
        KeyPrefix::make('last_run:App\\Foo:redis'),
        '900', '2026-05-04T12:00:00+00:00');

    expect(R::str('get', 'qmtest:last_run:App\\Foo'))->toBe('2026-05-04T12:00:00+00:00')
        ->and(R::str('get', 'qmtest:last_run:App\\Foo:redis'))->toBe('2026-05-04T12:00:00+00:00')
        ->and(R::int('ttl', 'qmtest:last_run:App\\Foo'))->toBeGreaterThan(890)->toBeLessThanOrEqual(900)
        ->and(R::int('ttl', 'qmtest:last_run:App\\Foo:redis'))->toBeGreaterThan(890)->toBeLessThanOrEqual(900);
});

it('ClassesRoster.lua ZADDs both rosters and EXPIREs only the per-connection one', function (): void {
    $redis = Redis::connection('default');
    $score = (string) Date::now()
        ->getTimestamp();

    RedisEval::exec($redis, LuaScripts::classesRoster(), 2,
        KeyPrefix::make('classes'),
        KeyPrefix::make('classes:redis'),
        $score, 'App\\Foo', (string) 2592000);

    expect(R::raw('zrange', 'qmtest:classes', 0, -1))->toContain('App\\Foo')
        ->and(R::raw('zrange', 'qmtest:classes:redis', 0, -1))->toContain('App\\Foo')
        // Aggregate roster has NO whole-key TTL — pruned by snapshot command.
        ->and(R::int('ttl', 'qmtest:classes'))->toBe(-1)
        ->and(R::int('ttl', 'qmtest:classes:redis'))->toBeGreaterThan(2592000 - 60)
        ->toBeLessThanOrEqual(2592000);
});
