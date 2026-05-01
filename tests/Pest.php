<?php declare(strict_types=1);

use DG\BypassFinals;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Tests\TestCase;

// Strip `final` from a tightly-scoped allowlist of package classes
// during the test run so Mockery can substitute `final readonly class`
// services. Process-wide enable is too broad — it would let tests
// silently start mocking final classes that real consumers can never
// construct, hiding package-boundary regressions until release. The
// allowlist below gates which file paths get bytecode-rewritten;
// everything else keeps `final` in the test process and matches the
// shipped artifact exactly.
BypassFinals::enable();
BypassFinals::allowPaths([
    '*/src/QueueInsights.php',
]);

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
