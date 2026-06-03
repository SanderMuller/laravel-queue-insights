<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

/**
 * Connection-drift coverage. Each test runs the (dispatcher-connection,
 * worker-connection) mismatch scenario from the spec's §1.5 keyspace audit
 * twice:
 *
 *  - Without `connection_aliases`: producer/worker keys diverge (the bug).
 *  - With `connection_aliases.redis = 'redis-staging'`: both sides converge
 *    on the canonical alias.
 *
 * Phase 0 in the queue-connection-drift spec required these tests to fail
 * on `main` BEFORE the fix; Phase 1 wired ConnectionAlias::canonical into
 * every listener's `$event->connectionName` read so they now pass.
 */
beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.connection_aliases', []);
});

function driftPendingEvent(string $uuid, string $connection, string $queue = 'premium-calculator'): JobQueued
{
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Premium\\CalculatePremium']);

    return new JobQueued(
        connectionName: $connection,
        queue: $queue,
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => 'App\\Jobs\\Premium\\CalculatePremium'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );
}

function driftJobMock(string $uuid, string $queue = 'premium-calculator'): Job&MockInterface
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\Premium\\CalculatePremium']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Premium\\CalculatePremium');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn($uuid);
    $job->shouldReceive('hasFailed')->andReturn(false);
    $job->shouldReceive('isReleased')->andReturn(false);

    return $job;
}

it('writes pending-zset under the dispatcher connection without aliases (reproduces drift)', function (): void {
    $uuid = '01HQDRFT0000000000000000P1';

    // Dispatcher = 'redis', worker = 'redis-staging' (Horizon supervisor).
    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    expect(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(0)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'connection'))->toBe('redis');
});

it('canonicalises pending-zset under the alias target when connection_aliases is set', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000P2';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    // Producer wrote to canonical side; non-canonical side stays empty.
    expect(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'connection'))->toBe('redis-staging');
});

it('reconciles queued→processing transition under aliases (the core bug fix)', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000P3';

    // Producer on 'redis', worker on 'redis-staging' — pre-fix: pending row
    // orphaned under 'redis', inflight written under 'redis-staging'.
    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    /** @var Job&MockInterface $procJob */
    $procJob = Mockery::mock(Job::class);
    $procJob->shouldReceive('uuid')->andReturn($uuid);
    $procJob->shouldReceive('getQueue')->andReturn('premium-calculator');
    $procJob->shouldReceive('attempts')->andReturn(1);
    (new RecordJobProcessing())->handle(new JobProcessing(connectionName: 'redis-staging', job: $procJob));

    // Pending zset cleared on canonical side; inflight on canonical side.
    expect(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(0)
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis-staging:premium-calculator'))->toBe(1)
        // No orphan keys under the non-canonical side.
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0)
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis:premium-calculator'))->toBe(0);
});

it('reconciles queued→processed lifecycle under aliases', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000P4';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    $event = new JobProcessed(connectionName: 'redis-staging', job: driftJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:premium-calculator'))->toBe(0);
});

it('reconciles queued→failed lifecycle under aliases', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000P5';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    $event = new JobFailed(connectionName: 'redis-staging', job: driftJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(0);
});

it('keeps a queued-only row visible under the canonical key until TTL', function (): void {
    // Per Codex round 2: queued-only is supposed to remain in the canonical
    // pending zset until TTL or migration — NOT no-orphans. Asserts continued
    // visibility under canonical.
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000P6';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));

    expect(R::int('zcard', 'qmtest:pending-zset:redis-staging:premium-calculator'))->toBe(1)
        ->and(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(1);
});

it('routes the per-connection class roster through the canonical side', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000C1';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));
    $event = new JobProcessed(connectionName: 'redis-staging', job: driftJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:classes:redis-staging'))->toBe(1)
        ->and(R::int('exists', 'qmtest:classes:redis'))->toBe(0);
});

it('routes per-class processed-total counter through the canonical side', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000C2';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));
    $event = new JobProcessed(connectionName: 'redis-staging', job: driftJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    // KeyPrefix::classKey('processed-total', $class, $connection) canonicalises.
    expect(R::int('exists', 'qmtest:processed-total:App\\Jobs\\Premium\\CalculatePremium:redis-staging'))->toBe(1)
        ->and(R::int('exists', 'qmtest:processed-total:App\\Jobs\\Premium\\CalculatePremium:redis'))->toBe(0);
});

it('routes the per-connection completed stream through the canonical side', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000C3';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));
    $event = new JobProcessed(connectionName: 'redis-staging', job: driftJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:completed:connection:redis-staging'))->toBe(1)
        ->and(R::int('exists', 'qmtest:completed:connection:redis'))->toBe(0);
});

it('routes per-class failed-total counter through the canonical side on JobFailed', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000F1';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));
    $event = new JobFailed(connectionName: 'redis-staging', job: driftJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    // KeyPrefix::classKey('failed-total', $class, $connection) canonicalises.
    expect(R::int('exists', 'qmtest:failed-total:App\\Jobs\\Premium\\CalculatePremium:redis-staging'))->toBe(1)
        ->and(R::int('exists', 'qmtest:failed-total:App\\Jobs\\Premium\\CalculatePremium:redis'))->toBe(0);
});

it('routes wait-time sample set through the canonical side on processing', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);
    $uuid = '01HQDRFT0000000000000000W1';

    (new RecordJobQueued())->handle(driftPendingEvent($uuid, 'redis'));
    // Producer wrote `pushed:{uuid}` (microtime float) so RecordJobProcessing
    // can compute wait_ms and write the sample.
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn('premium-calculator');
    $job->shouldReceive('attempts')->andReturn(1);
    (new RecordJobProcessing())->handle(new JobProcessing(connectionName: 'redis-staging', job: $job));

    expect(R::int('zcard', 'qmtest:wait:redis-staging:premium-calculator'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:wait:redis:premium-calculator'))->toBe(0);
});
