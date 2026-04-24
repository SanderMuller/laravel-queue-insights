<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
});

it('sets max_ms on first write', function (): void {
    $key = 'qmtest:duration:FooJob';
    $script = LuaScripts::updateMaxDuration();

    expect(R::int('eval', $script, 1, $key, '150'))->toBe(1)
        ->and(R::int('hget', $key, 'max_ms'))->toBe(150);
});

it('only overwrites when the candidate is greater', function (): void {
    $key = 'qmtest:duration:FooJob';
    $script = LuaScripts::updateMaxDuration();

    RedisEval::exec(Redis::connection('default'), $script, 1, $key, '150');

    expect(R::int('eval', $script, 1, $key, '100'))->toBe(0)
        ->and(R::int('hget', $key, 'max_ms'))->toBe(150)
        ->and(R::int('eval', $script, 1, $key, '150'))->toBe(0)
        ->and(R::int('hget', $key, 'max_ms'))->toBe(150)
        ->and(R::int('eval', $script, 1, $key, '250'))->toBe(1)
        ->and(R::int('hget', $key, 'max_ms'))->toBe(250);
});

it('produces the correct max across many interleaved writes', function (): void {
    $key = 'qmtest:duration:Concurrent';
    $script = LuaScripts::updateMaxDuration();

    $samples = [7, 42, 3, 99, 1, 500, 12, 500, 999, 250];
    foreach ($samples as $ms) {
        R::int('eval', $script, 1, $key, (string) $ms);
    }

    expect(R::int('hget', $key, 'max_ms'))->toBe(max($samples));
});
