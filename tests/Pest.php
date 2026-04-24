<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection as RedisConnection;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Driver-agnostic XADD helper for test seeding — phpredis and Predis have different
 * xAdd() signatures, so route through RedisEval::exec() to keep one code path.
 *
 * @param  array<string, string>  $fields
 */
function seedStream(RedisConnection $redis, string $key, array $fields, string $id = '*'): void
{
    $flat = [];
    foreach ($fields as $k => $v) {
        $flat[] = $k;
        $flat[] = $v;
    }

    RedisEval::exec(
        $redis,
        "return redis.call('XADD', KEYS[1], ARGV[1], unpack(ARGV, 2))",
        1,
        $key,
        $id,
        ...$flat,
    );
}
