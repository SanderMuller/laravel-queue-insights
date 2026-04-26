<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\QueueInsights;
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
 * Seed a pending-tracking entry directly via Redis. Bypasses the listener
 * so the service-layer tests stay focused on the read path semantics — the
 * listener-side write path is exercised in PendingTrackingTest.
 */
function seedPending(string $uuid, string $connection, string $queue, string $class, int $queuedAt, ?int $availableAt = null): void
{
    $availableAt ??= $queuedAt;

    foreach ([
        'connection' => $connection,
        'queue' => $queue,
        'class' => $class,
        'queued_at' => (string) $queuedAt,
        'available_at' => (string) $availableAt,
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:' . $uuid, $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:' . $connection . ':' . $queue, $availableAt, $uuid]);
}

it('returns pending jobs (available_at <= now) ordered oldest-first', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPending('uuid-c', 'redis', 'work', 'App\\Jobs\\C', $now - 5);
    seedPending('uuid-a', 'redis', 'work', 'App\\Jobs\\A', $now - 30);
    seedPending('uuid-b', 'redis', 'work', 'App\\Jobs\\B', $now - 15);

    $rows = (new QueueInsights())->pendingJobs('redis', 'work');

    expect(array_column($rows, 'uuid'))->toBe(['uuid-a', 'uuid-b', 'uuid-c'])
        ->and(array_column($rows, 'class'))->toBe(['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C']);
});

it('returns delayed jobs (available_at > now) soonest-first', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPending('uuid-far', 'redis', 'work', 'App\\Jobs\\Far', $now, $now + 3600);
    seedPending('uuid-soon', 'redis', 'work', 'App\\Jobs\\Soon', $now, $now + 60);
    seedPending('uuid-mid', 'redis', 'work', 'App\\Jobs\\Mid', $now, $now + 600);

    $rows = (new QueueInsights())->delayedJobs('redis', 'work');

    expect(array_column($rows, 'uuid'))->toBe(['uuid-soon', 'uuid-mid', 'uuid-far']);
});

it('separates pending vs delayed by available_at <= now boundary', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPending('p1', 'redis', 'work', 'App\\P', $now - 10);
    seedPending('p2', 'redis', 'work', 'App\\P', $now);          // exactly now → pending
    seedPending('d1', 'redis', 'work', 'App\\D', $now, $now + 1); // 1s in the future → delayed

    $svc = new QueueInsights();

    expect(array_column($svc->pendingJobs('redis', 'work'), 'uuid'))->toBe(['p1', 'p2'])
        ->and(array_column($svc->delayedJobs('redis', 'work'), 'uuid'))->toBe(['d1']);
});

it('skips zset entries whose hash is missing (race-condition guard)', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPending('uuid-good', 'redis', 'work', 'App\\Good', $now);

    // Simulate a race where the hash was already DEL'd (e.g. RecordJobProcessing
    // ran between our ZRANGEBYSCORE and HGETALL) but the zset member lingers.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:work', $now - 1, 'uuid-ghost']);

    $rows = (new QueueInsights())->pendingJobs('redis', 'work');

    // Ghost is silently dropped.
    expect(array_column($rows, 'uuid'))->toBe(['uuid-good']);
});

it('respects the limit argument', function (): void {
    $now = Date::now()
        ->getTimestamp();
    foreach (range(0, 9) as $i) {
        seedPending(sprintf('uuid-%02d', $i), 'redis', 'work', 'App\\Jobs\\X', $now - (10 - $i));
    }

    $rows = (new QueueInsights())->pendingJobs('redis', 'work', 3);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'uuid'))->toBe(['uuid-00', 'uuid-01', 'uuid-02']);
});

it('returns an empty array when no pending tracking exists for the queue', function (): void {
    $svc = new QueueInsights();

    expect($svc->pendingJobs('redis', 'unknown'))
        ->toBeEmpty()
        ->and($svc->delayedJobs('redis', 'unknown'))
        ->toBeEmpty();
});

it('canonicalizes the queue input so SQS URLs and plain names hit the same key', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPending('uuid-x', 'sqs', 'reports', 'App\\Reports', $now);

    $svc = new QueueInsights();

    // Plain canonical name and the SQS URL form both resolve to "reports".
    expect(array_column($svc->pendingJobs('sqs', 'reports'), 'uuid'))->toBe(['uuid-x'])
        ->and(array_column($svc->pendingJobs('sqs', 'https://sqs.us-east-1.amazonaws.com/123/reports'), 'uuid'))->toBe(['uuid-x']);
});

it('pendingTrackedCount wraps ZCARD', function (): void {
    seedPending('a', 'redis', 'work', 'App\\X', Date::now()
        ->getTimestamp() - 10);
    seedPending('b', 'redis', 'work', 'App\\X', Date::now()
        ->getTimestamp() - 5);
    seedPending('c', 'redis', 'work', 'App\\X', Date::now()
        ->getTimestamp() + 60); // delayed

    $svc = new QueueInsights();

    // Both pending + delayed count toward the tracked total — the drift signal
    // compares against `liveDepth + liveDelayed`, not against pending alone.
    expect($svc->pendingTrackedCount('redis', 'work'))->toBe(3)
        ->and($svc->pendingTrackedCount('redis', 'unknown'))->toBe(0);
});
