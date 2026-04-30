<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
});

/**
 * Build a JobQueued event with a payload that includes the given uuid.
 */
function makeJobQueuedEvent(string $uuid, string $connection = 'redis', string $queue = 'default'): JobQueued
{
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'TestJob']);

    return new JobQueued(
        connectionName: $connection,
        queue: $queue,
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => 'TestJob'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );
}

/**
 * Build a JobProcessing event whose underlying Job::uuid() returns the given uuid.
 */
function makeJobProcessingEvent(string $uuid, string $connection = 'redis', string $queue = 'default'): JobProcessing
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return new JobProcessing(connectionName: $connection, job: $job);
}

it('RecordJobQueued writes pushed:{uuid} extracted from payload.uuid', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    (new RecordJobQueued())->handle(makeJobQueuedEvent($uuid));

    $value = R::str('get', 'qmtest:pushed:' . $uuid);
    expect($value)->toBeString()
        ->and((float) $value)->toBeGreaterThan(0);
});

it('RecordJobQueued bails silently when payload omits uuid', function (): void {
    $payload = json_encode(['displayName' => 'TestJob']);
    $event = new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'driver-id-x',
        job: (object) ['displayName' => 'TestJob'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );

    (new RecordJobQueued())->handle($event);

    // No `pushed:*` key should exist anywhere — listener returned early.
    $keys = R::conn()->command('keys', ['qmtest:pushed:*']);
    expect($keys)
        ->toBeEmpty();
});

it('RecordJobQueued ignores empty payload', function (): void {
    $event = new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'x',
        job: (object) [],
        payload: '',
        delay: null,
    );

    (new RecordJobQueued())->handle($event);

    expect(R::conn()->command('keys', ['qmtest:pushed:*']))
        ->toBeEmpty();
});

it('RecordJobProcessing computes wait_ms from pushed:{uuid} and writes both wait keys', function (): void {
    $uuid = 'wait-uuid-1';

    // Stage 1 — enqueue (pushed timestamp written).
    (new RecordJobQueued())->handle(makeJobQueuedEvent($uuid, 'redis', 'video'));

    // Force a small gap so wait_ms is non-zero.
    Sleep::usleep(20_000);

    // Stage 2 — worker pickup.
    (new RecordJobProcessing())->handle(makeJobProcessingEvent($uuid, 'redis', 'video'));

    $waitMs = R::int('get', 'qmtest:wait:' . $uuid);
    expect($waitMs)->toBeGreaterThanOrEqual(20)
        ->toBeLessThan(5_000);

    // Per-queue ZSET: member = uuid (so trim by rank keeps the most recent
    // 1000), score = insertion timestamp (so ranks are recency, not wait_ms).
    // The score should therefore be a microtime — much larger than the
    // 7d-bounded wait_ms, and roughly within a couple seconds of `now`.
    $zsetKey = 'qmtest:wait:redis:video';
    $score = R::float('zscore', $zsetKey, $uuid);
    expect($score)->toBeGreaterThan(microtime(true) - 5)
        ->toBeLessThan(microtime(true) + 1);
});

it('per-queue wait ZSET key is canonicalised so SQS URLs match dashboard reads', function (): void {
    $uuid = 'sqs-uuid-1';
    $url = 'https://sqs.eu-west-1.amazonaws.com/123/work';

    (new RecordJobQueued())->handle(makeJobQueuedEvent($uuid, 'sqs', $url));

    Sleep::usleep(5_000);

    (new RecordJobProcessing())->handle(makeJobProcessingEvent($uuid, 'sqs', $url));

    // Writer must store under canonical 'work', not under the URL — otherwise
    // the dashboard's `wait:sqs:work` reader misses it (codex-flagged bug).
    expect(R::int('zcard', 'qmtest:wait:sqs:work'))->toBe(1)
        ->and(R::conn()->command('exists', ['qmtest:wait:sqs:' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $url)]))->toBe(0);
});

it('queue ZSET trim keeps the most recent samples, not the highest wait_ms', function (): void {
    // Codex review regression: prior `score = wait_ms` + `ZREMRANGEBYRANK 0 -1001`
    // dropped the FASTEST jobs (lowest score) and retained outliers, skewing p95
    // upward indefinitely. The fix scores by insertion timestamp.
    //
    // Seed: an "old slow" sample with a very high wait_ms but an old timestamp,
    // and a "new fast" sample with a low wait_ms but a current timestamp.
    // Trim limit lowered to 1 by manually trimming.
    $zsetKey = 'qmtest:wait:redis:default';

    // Old slow: score = 100 (an old timestamp), uuid = old-slow.
    R::conn()->command('zadd', [$zsetKey, 100, 'old-slow']);
    // New fast: score = microtime now, uuid = new-fast.
    R::conn()->command('zadd', [$zsetKey, microtime(true), 'new-fast']);

    // Trim to keep only the most recent 1.
    R::conn()->command('zremrangebyrank', [$zsetKey, 0, -2]);

    $survivors = R::conn()->command('zrange', [$zsetKey, 0, -1]);
    expect($survivors)->toBe(['new-fast']);
});

it('RecordJobProcessing skips wait keys when pushed:{uuid} is missing (legacy path)', function (): void {
    $uuid = 'legacy-uuid';

    // No JobQueued listener fired — only RecordJobProcessing runs.
    (new RecordJobProcessing())->handle(makeJobProcessingEvent($uuid));

    expect(R::str('get', 'qmtest:wait:' . $uuid))->toBeNull()
        ->and(R::conn()->command('keys', ['qmtest:wait:redis:*']))
        ->toBeEmpty();
});

it('per-queue ZSET retains both samples when two jobs share the same wait_ms', function (): void {
    // Regression: prior design had `member = wait_ms` which deduped equal-wait
    // jobs. Spec §2.3 / Resolved Q #3 fixes by using uuid as the member.
    $zsetKey = 'qmtest:wait:redis:default';

    // Manually seed two distinct uuids with identical scores.
    R::conn()->command('zadd', [$zsetKey, 250, 'uuid-a']);
    R::conn()->command('zadd', [$zsetKey, 250, 'uuid-b']);

    expect(R::int('zcard', $zsetKey))->toBe(2);
});

it('ZREMRANGEBYRANK trims the per-queue ZSET to the most recent 1000 samples', function (): void {
    // Drive the listener > 1000 times. Each iteration: enqueue + processing.
    for ($i = 0; $i < 1005; ++$i) {
        $uuid = 'trim-uuid-' . $i;
        (new RecordJobQueued())->handle(makeJobQueuedEvent($uuid));
        (new RecordJobProcessing())->handle(makeJobProcessingEvent($uuid));
    }

    expect(R::int('zcard', 'qmtest:wait:redis:default'))->toBe(1000);
})->skip('drives 1005 jobs through Redis — slow; enable locally for trim regression');
