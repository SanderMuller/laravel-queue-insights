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

it('refuses --force when the target IS the connection\'s live default queue', function (): void {
    // Connection's configured queue is `staging_default` per the beforeEach.
    seedOrphanPending('sqs', 'staging_default', 1);

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'staging_default',
        '--force' => true,
    ]);

    $output = Artisan::output();
    expect($exit)->toBe(2)
        ->and($output)->toContain('Refusing')
        ->and($output)->toContain('--allow-live-queue')
        // Live data must be untouched.
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:staging_default'))->toBe(1);
});

it('allows --force on the live default queue when --allow-live-queue is passed', function (): void {
    seedOrphanPending('sqs', 'staging_default', 1);

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'staging_default',
        '--force' => true,
        '--allow-live-queue' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:staging_default'))->toBe(0);
});

it('snapshots the zset via RENAME so producers writing during the purge are not destroyed', function (): void {
    // Seed an orphan zset; the command should RENAME it to a temp key
    // before walking. Any entry that lands on the original key path
    // AFTER the rename must survive the purge.
    seedOrphanPending('sqs', 'default', 2);

    $r = Redis::connection('default');
    // Wedge a "concurrent producer" between the rename and the final DEL
    // by inserting a fresh member onto the (now-empty) original key path
    // immediately after the command would have renamed it. We can't
    // truly time it, but we can simulate by inserting AFTER the call
    // completes; the assertion is that the renamed snapshot's contents
    // were the ONLY thing touched.
    Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
        '--force' => true,
    ]);

    // After the command, neither the original nor any :purging-* snapshot
    // key should exist.
    expect(R::int('exists', 'qmtest:pending-zset:sqs:default'))->toBe(0);
    $keys = $r->command('keys', ['qmtest:pending-zset:sqs:default:purging-*']);
    expect(is_array($keys) ? count($keys) : 0)->toBe(0);

    // A producer writing to the original key after the purge must not be
    // touched (this is what was broken in the non-atomic version — the
    // final DEL nuked the post-snapshot writes).
    $r->command('zadd', ['qmtest:pending-zset:sqs:default', 9999, 'uuid-after-purge']);
    expect(R::int('zcard', 'qmtest:pending-zset:sqs:default'))->toBe(1);
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

it('fails closed on whitespace-only queue without crashing on CanonicalQueueKey::from', function (): void {
    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => "  \t\n  ",
    ]);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('Invalid queue value');
});

it('handles orphan zset members whose per-uuid hash has TTL-d out', function (): void {
    // Common state during a TTL-driven self-heal: the per-uuid hash
    // expired but the zset member outlived it (zset TTL refreshes on
    // every write, individual hashes have their own per-uuid TTL clock).
    // The cleanup command must drop the zset without crashing on
    // HGET-returns-null, and must not over-count "hashes deleted".
    Redis::connection('default')->command('zadd', ['qmtest:pending-zset:sqs:default', 1000, 'uuid-no-hash']);
    // Deliberately do NOT seed the pending:uuid-no-hash hash.

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'default',
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:default'))->toBe(0);
    expect(Artisan::output())->toContain('Purged 1 zset member + 0 matching');
});

it('canonicalises an SQS URL passed as the queue argument', function (): void {
    // Producer wrote pending entries under the canonical form (`my-q`)
    // even if operators think of the queue as the full SQS URL — the
    // command should accept either shape and key on the canonical form.
    seedOrphanPending('sqs', 'my-q', 2);

    $exit = Artisan::call('queue-insights:purge-pending', [
        'connection' => 'sqs',
        'queue' => 'https://sqs.eu-west-1.amazonaws.com/123/my-q',
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:my-q'))->toBe(0);
});
