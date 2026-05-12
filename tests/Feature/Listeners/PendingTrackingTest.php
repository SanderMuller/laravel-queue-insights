<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.pending.max_per_queue', 10000);
    config()->set('queue-insights.pending.ttl_seconds', 86400);
});

/**
 * Build a JobQueued event with a payload that includes the given uuid and
 * a configurable delay. Mirrors the helper used by WaitTimeCaptureTest but
 * exposes the `delay` parameter so we can exercise the immediate vs delayed
 * branches of `resolveAvailableAt()`.
 */
function makePendingEvent(
    string $uuid,
    string $connection = 'redis',
    string $queue = 'default',
    string $displayName = 'App\\Jobs\\PendingTestJob',
    ?int $delay = null,
): JobQueued {
    $payload = json_encode(['uuid' => $uuid, 'displayName' => $displayName]);

    return new JobQueued(
        connectionName: $connection,
        queue: $queue,
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => $displayName],
        payload: $payload === false ? '' : $payload,
        delay: $delay,
    );
}

it('writes pending hash + zset for an immediate (no-delay) queued job', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $before = Date::now()
        ->getTimestamp();

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'class'))->toBe('App\\Jobs\\PendingTestJob')
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'connection'))
        ->toBe('redis')
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'queue'))
        ->toBe('work');

    $queuedAt = (int) R::str('hget', 'qmtest:pending:' . $uuid, 'queued_at');
    $availableAt = (int) R::str('hget', 'qmtest:pending:' . $uuid, 'available_at');

    expect($queuedAt)->toBeGreaterThanOrEqual($before)
        ->and($availableAt)
        ->toBe($queuedAt);

    $score = R::float('zscore', 'qmtest:pending-zset:redis:work', $uuid);
    expect($score)->toBe((float) $availableAt);
});

it('uses queued_at + delay seconds when the event delay is an int', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAA';
    $delay = 600; // 10 minutes
    $before = Date::now()
        ->getTimestamp();

    (new RecordJobQueued())->handle(makePendingEvent($uuid, delay: $delay));

    $queuedAt = (int) R::str('hget', 'qmtest:pending:' . $uuid, 'queued_at');
    $availableAt = (int) R::str('hget', 'qmtest:pending:' . $uuid, 'available_at');

    expect($queuedAt)->toBeGreaterThanOrEqual($before)
        ->and($availableAt)
        ->toBe($queuedAt + $delay)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis:default', $uuid))
        ->toBe((float) $availableAt);
});

it('skips pending tracking when displayName is missing from the payload', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAC';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, displayName: ''));

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:default'))
        ->toBe(0);
});

it('no-ops when pending.enabled is false', function (): void {
    config()->set('queue-insights.pending.enabled', false);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAD';

    (new RecordJobQueued())->handle(makePendingEvent($uuid));

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:default'))
        ->toBe(0);

    // Wait-time tracking still happens — pending.enabled gates only the new
    // hash/zset writes, not the existing pushed:{uuid} stamp.
    expect(R::str('get', 'qmtest:pushed:' . $uuid))->not->toBeNull();
});

it('respects max_per_queue cap by dropping the lowest-score uuid first', function (): void {
    config()->set('queue-insights.pending.max_per_queue', 3);

    // Push 5 jobs with deliberate delays so the score order is predictable.
    // delay-seconds maps to score relative to queued_at; lower delay = lower score.
    foreach ([0, 100, 200, 300, 400] as $i => $delay) {
        $uuid = sprintf('uuid-%02d', $i);
        (new RecordJobQueued())->handle(makePendingEvent($uuid, delay: $delay));
    }

    // After cap (3), the two lowest-score uuids should have been removed.
    expect(R::int('zcard', 'qmtest:pending-zset:redis:default'))->toBe(3);

    expect(R::float('zscore', 'qmtest:pending-zset:redis:default', 'uuid-00'))->toBe(0.0)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis:default', 'uuid-01'))
        ->toBe(0.0)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis:default', 'uuid-02'))
        ->toBeGreaterThan(0.0)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis:default', 'uuid-03'))
        ->toBeGreaterThan(0.0)
        ->and(R::float('zscore', 'qmtest:pending-zset:redis:default', 'uuid-04'))
        ->toBeGreaterThan(0.0);
});

it('sets the configured TTL on both keys', function (): void {
    config()->set('queue-insights.pending.ttl_seconds', 3600);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAE';

    (new RecordJobQueued())->handle(makePendingEvent($uuid));

    $hashTtl = R::int('ttl', 'qmtest:pending:' . $uuid);
    $zsetTtl = R::int('ttl', 'qmtest:pending-zset:redis:default');

    // Allow a 5s window for command latency between EXPIRE and our TTL read.
    expect($hashTtl)->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600);
    expect($zsetTtl)->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600);
});

/**
 * Build a JobProcessing event whose underlying Job::uuid() returns the given uuid.
 */
function makePendingProcessingEvent(string $uuid, string $connection = 'redis', string $queue = 'default', int $attempts = 1): JobProcessing
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn($attempts);

    return new JobProcessing(connectionName: $connection, job: $job);
}

it('RecordJobProcessing transitions a uuid from pending → in-flight', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PROC';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));
    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(1)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:work'))
        ->toBe(1);

    (new RecordJobProcessing())->handle(makePendingProcessingEvent($uuid, 'redis', 'work'));

    // Hash kept (now stamped with state + started_at + attempts), uuid moved
    // from pending-zset to inflight-zset.
    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(1)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'state'))->toBe('in_flight')
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'started_at'))->not->toBeNull()
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'attempts'))->toBe('1')
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:work'))->toBe(0)
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis:work'))->toBe(1);
});

it('RecordJobProcessing stamps attempts > 1 on retry pickups', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69RETRY';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));
    (new RecordJobProcessing())->handle(makePendingProcessingEvent($uuid, 'redis', 'work', attempts: 3));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'attempts'))->toBe('3');
});

it('RecordJobProcessing transition is idempotent when no prior pending entry exists', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69IDEM';

    // No prior queued event — listener still stamps state on a fresh hash so
    // a worker that picked up a job we never saw queued (raw push, missed
    // event) still surfaces in the dashboard.
    (new RecordJobProcessing())->handle(makePendingProcessingEvent($uuid));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'state'))->toBe('in_flight')
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis:default'))->toBe(1);
});

it('RecordJobProcessing transition is a no-op when pending.enabled is false', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOOP';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));
    config()->set('queue-insights.pending.enabled', false);

    (new RecordJobProcessing())->handle(makePendingProcessingEvent($uuid, 'redis', 'work'));

    // Hash + pending-zset stay as-is (no state stamp, no inflight-zset write).
    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(1)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'state'))->toBeNull()
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:work'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:inflight-zset:redis:work'))->toBe(0);
});

/**
 * Mockery stub for an Illuminate\Contracts\Queue\Job that responds to every
 * method RecordJobProcessed / RecordJobFailed touch. Keeps the cleanup tests
 * focused on the pending-tracking removal rather than re-stubbing per case.
 */
function makePendingJobMock(string $uuid, string $queue = 'work'): Job&MockInterface
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\PendingTestJob']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\PendingTestJob');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn($uuid);

    return $job;
}

it('RecordJobProcessed cleans pending tracking as belt-and-suspenders', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PROD';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));

    $event = new JobProcessed(connectionName: 'redis', job: makePendingJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:work'))
        ->toBe(0);
});

it('RecordJobFailed cleans pending tracking as belt-and-suspenders', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAIL';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'redis', 'work'));

    $event = new JobFailed(connectionName: 'redis', job: makePendingJobMock($uuid), exception: new RuntimeException('boom'));
    (new RecordJobFailed(resolve(ResolveJobClass::class)))->handle($event);

    expect(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0)
        ->and(R::int('zcard', 'qmtest:pending-zset:redis:work'))
        ->toBe(0);
});

it('RecordJobFailed stamps failed-runtime:{uuid} when start:{uuid} was set', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69TIME';

    // Simulate RecordJobProcessing's start-microtime stamp, 250 ms ago.
    $start = microtime(true) - 0.25;
    Redis::connection('default')
        ->command('set', ['qmtest:start:' . $uuid, (string) $start]);

    $event = new JobFailed(connectionName: 'redis', job: makePendingJobMock($uuid), exception: new RuntimeException('boom'));
    (new RecordJobFailed(resolve(ResolveJobClass::class)))->handle($event);

    $runtimeMs = (int) (R::str('get', 'qmtest:failed-runtime:' . $uuid) ?? '');
    expect($runtimeMs)->toBeGreaterThanOrEqual(200)
        ->and($runtimeMs)->toBeLessThan(5000)
        // start:{uuid} is consumed.
        ->and(R::int('exists', 'qmtest:start:' . $uuid))->toBe(0);
});

it('RecordJobFailed skips failed-runtime:{uuid} when start:{uuid} is absent', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOSTRT';

    $event = new JobFailed(connectionName: 'redis', job: makePendingJobMock($uuid), exception: new RuntimeException('boom'));
    (new RecordJobFailed(resolve(ResolveJobClass::class)))->handle($event);

    expect(R::int('exists', 'qmtest:failed-runtime:' . $uuid))->toBe(0);
});

/**
 * Vapor/SQS regression: dispatcher calls `dispatch(...)` without an explicit
 * `->onQueue()`, so the SQS driver receives `$queue = null` at push time and
 * routes to its configured default (`SQS_QUEUE=staging_default` in Vapor).
 * The JobQueued event still carries an empty `$event->queue`, while the
 * worker-side popped Job exposes the real queue name on `getQueue()`. Before
 * the fix the listener stored `pending-zset:sqs:default` while the cleanup
 * path deleted `pending-zset:sqs:staging_default` — the pending entry never
 * cleared and `oldest_pending` tripped on long-completed jobs.
 *
 * Reproduces the divergence + asserts the fix keeps both writers on the
 * configured-default zset.
 */
it('aligns producer and worker zset keys when JobQueued carries an empty queue (Vapor/SQS)', function (): void {
    config()->set('queue.connections.sqs.queue', 'staging_default');

    $uuid = '01ARZ3NDEKTSV4RRFFQ69VAPR';

    // Producer side — dispatcher omitted ->onQueue(), $event->queue is ''.
    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'sqs', ''));

    expect(R::int('exists', 'qmtest:pending-zset:sqs:staging_default'))->toBe(1)
        ->and(R::int('exists', 'qmtest:pending-zset:sqs:default'))->toBe(0)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'queue'))->toBe('staging_default');

    // Worker side — popped job reports the real queue. Cleanup must delete
    // the same zset key the producer wrote.
    $event = new JobProcessed(connectionName: 'sqs', job: makePendingJobMock($uuid, 'staging_default'));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('zcard', 'qmtest:pending-zset:sqs:staging_default'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending:' . $uuid))->toBe(0);
});

it('producer falls back to the literal "default" when no connection default is configured', function (): void {
    config()->set('queue.connections.weird', []);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69WIRD';

    (new RecordJobQueued())->handle(makePendingEvent($uuid, 'weird', ''));

    expect(R::int('exists', 'qmtest:pending-zset:weird:default'))->toBe(1)
        ->and(R::str('hget', 'qmtest:pending:' . $uuid, 'queue'))->toBe('default');
});
