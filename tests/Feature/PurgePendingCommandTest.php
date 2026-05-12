<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue.connections.sqs', ['driver' => 'sync', 'queue' => 'staging_default']);
});

function seedOrphanPending(string $connection, string $queue, int $count): void
{
    $zsetKey = "qmtest:pending-zset:{$connection}:{$queue}";
    $r = Redis::connection('default');
    for ($i = 0; $i < $count; ++$i) {
        $uuid = sprintf('uuid-orphan-%03d', $i);
        $r->command('zadd', [$zsetKey, 1000 + $i, $uuid]);
        $r->command('hset', ["qmtest:pending:{$uuid}", 'queue', $queue]);
        $r->command('hset', ["qmtest:pending:{$uuid}", 'connection', $connection]);
    }
}

it('dry-runs without --force and reports the target without deleting', function (): void {
    seedOrphanPending('sqs', 'default', 3);

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
    ]);

    expect($exit)->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:sqs:default'))->toBe(3)
        ->and(R::int('exists', 'qmtest:pending:uuid-orphan-000'))->toBe(1);

    $output = Artisan::output();
    expect($output)->toContain('members : 3')
        ->and($output)->toContain('Dry-run');
});

it('purges the zset + matching per-uuid hashes with --force', function (): void {
    seedOrphanPending('sqs', 'default', 3);

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:default'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending:uuid-orphan-000'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending:uuid-orphan-001'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending:uuid-orphan-002'))->toBe(0);

    expect(Artisan::output())->toContain('Purged 3 zset members + 3 matching pending:{uuid} hashes');
});

it('refuses to touch per-uuid hashes whose queue field points elsewhere', function (): void {
    // Seed an orphan zset entry whose uuid hash claims a different queue —
    // shouldn't happen in practice (the bug writes them under matching keys),
    // but the field-match guard makes the command safe even when the operator
    // re-runs it after a partial cleanup.
    Redis::connection('default')->command('zadd', ['qmtest:pending-zset:sqs:default', 1000, 'uuid-mismatch']);
    Redis::connection('default')->command('hset', ['qmtest:pending:uuid-mismatch', 'queue', 'other_queue']);

    Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
        '--force' => true,
    ]);

    expect(R::int('exists', 'qmtest:pending-zset:sqs:default'))->toBe(0) // zset still gone
        ->and(R::str('hget', 'qmtest:pending:uuid-mismatch', 'queue'))->toBe('other_queue'); // hash preserved
});

it('reports nothing-to-do when the zset is already empty', function (): void {
    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No pending entries');
});

it('fails closed when the connection is not configured', function (): void {
    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'nonexistent_conn',
        'queue' => 'default',
    ]);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('not configured');
});

it('fails closed when queue is empty', function (): void {
    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => '',
    ]);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('cannot be empty');
});
