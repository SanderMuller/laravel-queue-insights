<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.connection_aliases', []);
});

it('exits cleanly when no non-identity aliases are configured', function (): void {
    $this->artisan('queue-insights:migrate-aliases')
        ->expectsOutputToContain('No non-identity `connection_aliases` configured')
        ->assertSuccessful();
});

it('treats identity-only entries as nothing to migrate', function (): void {
    config()->set('queue-insights.connection_aliases', [
        'redis-staging' => 'redis-staging',
    ]);

    $this->artisan('queue-insights:migrate-aliases')
        ->expectsOutputToContain('No non-identity `connection_aliases` configured')
        ->assertSuccessful();
});

it('reports planned migrations without writing when --force is omitted', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 2, 'uuid-2']);

    $this->artisan('queue-insights:migrate-aliases')
        ->expectsOutputToContain('redis → redis-staging')
        ->expectsOutputToContain('Dry-run')
        ->assertSuccessful();

    // Source key untouched, target empty.
    expect(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(2)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(0);
});

it('migrates a pending zset onto the canonical key with --force, preserving scores', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 100, 'uuid-1']);
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 200, 'uuid-2']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    expect(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(2)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis-staging:premium-calculator', 'uuid-1'))->toBe(100.0)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis-staging:premium-calculator', 'uuid-2'))->toBe(200.0);
});

it('migrates an inflight zset family alongside pending', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    R::conn()->command('zadd', ['qmtest:inflight-zset:redis:premium-calculator', 1, 'uuid-2']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    expect(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis-staging:premium-calculator'))->toBe(1)
        ->and(R::int('exists', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0)
        ->and(R::int('exists', 'qmtest:inflight-zset:redis:premium-calculator'))->toBe(0);
});

it('rewrites pending:{uuid}.connection from pre-alias to canonical', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    R::conn()->command('hset', ['qmtest:pending:uuid-1', 'connection', 'redis']);
    R::conn()->command('hset', ['qmtest:pending:uuid-1', 'queue', 'premium-calculator']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    expect(R::str('hget', 'qmtest:pending:uuid-1', 'connection'))->toBe('redis-staging')
        // Other fields untouched.
        ->and(R::str('hget', 'qmtest:pending:uuid-1', 'queue'))->toBe('premium-calculator');
});

it('does not rewrite pending:{uuid}.connection when the field already holds the canonical name', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    // Partial-rollout state: zset still under pre-alias, but hash was already
    // canonicalised on the producer side post-rollout.
    R::conn()->command('hset', ['qmtest:pending:uuid-1', 'connection', 'redis-staging']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    // Hash kept as-is; only zset migrated.
    expect(R::str('hget', 'qmtest:pending:uuid-1', 'connection'))->toBe('redis-staging')
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(1);
});

it('uses ZADD NX so a post-rollout canonical write is preserved on conflict', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    // Pre-alias entry on the source side.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 100, 'uuid-1']);
    // Same uuid already on the canonical side with a different score (e.g. a
    // worker fired RecordJobProcessing post-rollout, transitioned to inflight,
    // then back to pending on retry, with a fresh available_at).
    R::conn()->command('zadd', ['qmtest:pending-zset:redis-staging:premium-calculator', 999, 'uuid-1']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    // Canonical score preserved — NX means the pre-alias write didn't clobber.
    expect(R::float('zscore', 'qmtest:pending-zset:redis-staging:premium-calculator', 'uuid-1'))->toBe(999.0)
        ->and(R::int('exists', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0);
});

it('handles multiple distinct aliases independently', function (): void {
    config()->set('queue-insights.connection_aliases', [
        'redis-legacy' => 'redis-prod',
        'redis-staging-old' => 'redis-staging',
    ]);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis-legacy:work', 1, 'uuid-a']);
    R::conn()->command('zadd', ['qmtest:pending-zset:redis-staging-old:reports', 1, 'uuid-b']);

    $this->artisan('queue-insights:migrate-aliases', ['--force' => true])
        ->assertSuccessful();

    expect(R::int('zcard', 'qmtest:pending-zset:redis-prod:work'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:reports'))->toBe(1)
        ->and(R::int('exists', 'qmtest:pending-zset:redis-legacy:work'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending-zset:redis-staging-old:reports'))->toBe(0);
});

it('prints the quiescence runbook on dry-run', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:work', 1, 'uuid-1']);

    $this->artisan('queue-insights:migrate-aliases')
        ->expectsOutputToContain('Pause new dispatches')
        ->expectsOutputToContain('Drain workers')
        ->expectsOutputToContain('Re-run with --force')
        ->assertSuccessful();
});
