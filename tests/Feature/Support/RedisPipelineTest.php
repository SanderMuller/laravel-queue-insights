<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\EagerCommandCollector;
use SanderMuller\QueueInsights\Support\RedisPipeline;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
});

it('returns a numerically-indexed list in queue order for the active driver', function (): void {
    R::conn()->command('set', ['a', '1']);
    R::conn()->command('set', ['b', '2']);
    R::conn()->command('set', ['c', '3']);

    $results = RedisPipeline::run(R::conn(), static function (mixed $pipe): void {
        $pipe->get('a');
        $pipe->get('b');
        $pipe->get('c');
    });

    // Both predis and phpredis must surface a positional list of replies.
    expect($results)->toHaveCount(3)
        ->and(array_keys($results))->toBe([0, 1, 2])
        ->and($results[0])->toEqual('1')
        ->and($results[1])->toEqual('2')
        ->and($results[2])->toEqual('3');
});

it('preserves hash-shaped replies in the same indexed slot', function (): void {
    R::conn()->command('hset', ['h:1', 'a', '10']);
    R::conn()->command('hset', ['h:1', 'b', '20']);
    R::conn()->command('hset', ['h:2', 'x', '100']);

    $results = RedisPipeline::run(R::conn(), static function (mixed $pipe): void {
        $pipe->hgetall('h:1');
        $pipe->hgetall('h:2');
    });

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeArray()
        ->and($results[1])->toBeArray();

    // Hash field reads — guard the array cast through the same helper the
    // production readers use so this test never depends on driver shape.
    expect($results[0])->toMatchArray(['a' => '10', 'b' => '20']);
    expect($results[1])->toMatchArray(['x' => '100']);
});

it('returns an empty list when no commands are queued', function (): void {
    $results = RedisPipeline::run(R::conn(), static function (): void {
        // intentionally empty
    });

    expect($results)
        ->toBeEmpty();
});

it('eager execution reproduces the native pipeline reply shape on the active driver', function (): void {
    R::conn()->command('set', ['p:a', '1']);
    R::conn()->command('hset', ['p:h', 'f', '9']);
    R::conn()->command('zadd', ['p:z', 5, 'm']);

    // EagerCommandCollector is the fallback RedisPipeline::run uses on
    // cluster connections (which have no pipeline()). It must yield
    // byte-identical replies to the native pipeline on the active driver,
    // or cluster reads would silently shift shape vs single-node reads —
    // `zrange … withscores` is the shape most likely to diverge, so it's
    // in the sequence on purpose.
    $callback = static function (mixed $pipe): void {
        $pipe->get('p:a');
        $pipe->hgetall('p:h');
        $pipe->zrange('p:z', 0, -1, ['withscores' => true]);
        $pipe->zcard('p:z');
    };

    $viaPipeline = RedisPipeline::run(R::conn(), $callback);

    $collector = new EagerCommandCollector(R::conn());
    $callback($collector);

    expect($collector->results())->toEqual($viaPipeline);
});
