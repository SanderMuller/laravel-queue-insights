<?php declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;
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
