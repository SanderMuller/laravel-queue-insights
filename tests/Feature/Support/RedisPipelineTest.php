<?php declare(strict_types=1);

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

    $results = RedisPipeline::run(R::conn(), static function ($pipe): void {
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

    $results = RedisPipeline::run(R::conn(), static function ($pipe): void {
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

    expect($results)->toBe([]);
});
