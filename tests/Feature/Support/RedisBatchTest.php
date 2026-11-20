<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
});

it('returns replies in queue order, including evals in the driver shape', function (): void {
    $batch = new RedisBatch(R::conn());

    $batch->command('setex', ['batch:a', 60, '1']);

    $evalIndex = $batch->eval("return redis.call('INCRBY', KEYS[1], ARGV[1])", 1, 'batch:n', '5');
    $getIndex = $batch->command('get', ['batch:a']);

    $results = $batch->execute();

    expect($results)->toHaveCount(3)
        ->and($evalIndex)->toBe(1)
        ->and($getIndex)->toBe(2)
        ->and($results[1])->toEqual(5)
        ->and($results[2])->toBe('1');
});

it('runs a single queued command without a pipeline', function (): void {
    $batch = new RedisBatch(R::conn());
    $batch->eval("return redis.call('SET', KEYS[1], ARGV[1])", 1, 'batch:single', 'v');

    $batch->execute();

    expect(R::str('get', 'batch:single'))->toBe('v');
});

it('returns an empty list and sends nothing when no commands are queued', function (): void {
    expect((new RedisBatch(R::conn()))->execute())
        ->toBeEmpty();
});

it('empties the queue after executing', function (): void {
    $batch = new RedisBatch(R::conn());
    $batch->command('incr', ['batch:count']);
    $batch->command('incr', ['batch:count']);
    $batch->execute();

    expect($batch->execute())
        ->toBeEmpty()
        ->and(R::int('get', 'batch:count'))->toBe(2);
});

it('throws on an error reply from a single command', function (): void {
    R::conn()->command('set', ['batch:string', 'x']);

    $batch = new RedisBatch(R::conn());
    $batch->command('hset', ['batch:string', 'field', 'value']);

    expect(fn (): array => $batch->execute())->toThrow('WRONGTYPE');
});

it('throws on an error reply inside the pipeline', function (): void {
    R::conn()->command('set', ['batch:string', 'x']);

    $batch = new RedisBatch(R::conn());
    $batch->command('setex', ['batch:ok', 60, '1']);
    $batch->command('hset', ['batch:string', 'field', 'value']);

    expect(fn (): array => $batch->execute())->toThrow('WRONGTYPE');
});

it('leaves the connection usable when a queued command throws before the pipeline is sent', function (): void {
    $batch = new RedisBatch(R::conn());
    $batch->command('setex', ['batch:first', 60, '1']);
    $batch->command('not-a-redis-command', []);

    try {
        $batch->execute();
    } catch (Throwable) {
        // Expected on both drivers.
    }

    R::conn()->command('set', ['batch:after', 'ok']);

    expect(R::str('get', 'batch:after'))->toBe('ok');
});
