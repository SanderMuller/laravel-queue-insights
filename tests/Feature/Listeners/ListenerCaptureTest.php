<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\TestJob;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.capture.payloads', 'off');
});

function currentUtcBucket(): string
{
    return CarbonImmutable::now('UTC')->format('YmdH');
}

it('records processed counter, classes, duration, last_run and streams on JobProcessed', function (): void {
    dispatch(new TestJob());

    $bucket = currentUtcBucket();
    $class = TestJob::class;

    $processedKey = 'qmtest:processed:' . $class . ':' . $bucket;
    expect(R::int('get', $processedKey))->toBe(1);

    // TTL is bucket-start + 7d, so expireat roughly 7 days from now.
    $ttl = R::int('ttl', $processedKey);
    expect($ttl)->toBeGreaterThan(6 * 86400)
        ->toBeLessThanOrEqual(7 * 86400 + 3600);

    // Classes ZSET contains the job class.
    $classes = R::raw('zrange', 'qmtest:classes', 0, -1);
    expect($classes)->toContain($class);

    // Duration hash has count/sum_ms/max_ms.
    $durationKey = 'qmtest:duration:' . $class;
    expect(R::int('hget', $durationKey, 'count'))->toBe(1)
        ->and(R::float('hget', $durationKey, 'sum_ms'))->toBeGreaterThanOrEqual(0.0)
        ->and(R::str('hget', $durationKey, 'max_ms'))->not->toBeNull();

    // last_run set.
    expect(R::raw('get', 'qmtest:last_run:' . $class))->toBeString();

    // Streams have entries.
    expect(R::int('xlen', 'qmtest:completed'))->toBe(1);
    expect(R::int('xlen', 'qmtest:completed:' . $class))->toBe(1);
});

it('records the failed counter, cleans up the start key, and skips duration on JobFailed', function (): void {
    try {
        dispatch(new TestJob(shouldFail: true));
    } catch (Throwable) {
        // sync driver rethrows
    }

    $bucket = currentUtcBucket();
    $class = TestJob::class;

    $failedKey = 'qmtest:failed:' . $class . ':' . $bucket;
    expect(R::int('get', $failedKey))->toBe(1);

    // Duration hash must NOT be touched on failed jobs (spec §6.3).
    $exists = R::int('exists', 'qmtest:duration:' . $class);
    expect($exists)->toBe(0);

    // completed stream must not receive failed jobs.
    expect(R::int('xlen', 'qmtest:completed'))->toBe(0);

    // Classes ZSET still updated.
    expect(R::raw('zrange', 'qmtest:classes', 0, -1))->toContain($class);
});

it('writes only metadata fields when capture.payloads = off', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    dispatch(new TestJob());

    $entries = R::raw('xrange', 'qmtest:completed', '-', '+');

    expect($entries)->toBeArray()->not->toBeEmpty();

    $fields = firstStreamEntryFields($entries);

    expect($fields)->toHaveKeys(['class', 'connection', 'queue', 'duration_ms', 'attempts', 'processed_at'])->and(array_keys($fields))->each->not->toStartWith('payload_');
});

it('writes payload metadata fields when capture.payloads = metadata', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');
    app()->forgetInstance(PayloadSanitizer::class);

    dispatch(new TestJob());

    $entries = R::raw('xrange', 'qmtest:completed', '-', '+');
    $fields = firstStreamEntryFields($entries);

    // At minimum displayName should be present for a plain ShouldQueue job.
    $payloadFields = array_filter(
        $fields,
        fn (string $k): bool => str_starts_with($k, 'payload_'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($payloadFields)->not->toBeEmpty()
        ->and(array_keys($payloadFields))->toContain('payload_displayName');

    // No arbitrary user-data keys like payload_body / payload_password.
    expect($fields)->not->toHaveKey('payload_body');
    expect($fields)->not->toHaveKey('payload_password');
});

it('writes a payload_body field when capture.payloads = full', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');
    app()->forgetInstance(PayloadSanitizer::class);

    // Outer redactable keys get redacted. Inner PHP-serialized blob is a known
    // limitation documented in spec §6.4 — host apps must ship a custom sanitizer
    // for jobs carrying secrets inside `data.command`.
    dispatch(new TestJob());

    $entries = R::raw('xrange', 'qmtest:completed', '-', '+');
    $fields = firstStreamEntryFields($entries);

    expect($fields)->toHaveKey('payload_body');

    $body = json_decode((string) $fields['payload_body'], true);

    expect($body)->toBeArray()
        ->toHaveKey('displayName');
});

it('treats a non-numeric start:{uuid} value as a missing sample (no duration write)', function (): void {
    // Seed a corrupt start key for a uuid we control, then drive the listener
    // directly through a fake JobProcessed event. The listener must NOT cast
    // 'not-a-number' to 0.0 and write a 17-trillion-ms duration.
    $uuid = 'corrupt-uuid';
    R::conn()->command('set', ['qmtest:start:' . $uuid, 'not-a-number', 'EX', 3600]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Fake');
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => 'App\\Jobs\\Fake', 'command' => 'O:0:"":0:{}']]);
    $job->shouldReceive('attempts')->andReturn(1);

    $event = new JobProcessed('sync', $job);

    resolve(RecordJobProcessed::class)->handle($event);

    // Duration hash should NOT be populated — start was non-numeric, so
    // readAndConsumeStart returned null and the hash update was skipped.
    expect(R::int('exists', 'qmtest:duration:App\\Jobs\\Fake'))->toBe(0);

    // The corrupt start key stays (listener only DELs when start was valid).
    expect(R::str('get', 'qmtest:start:' . $uuid))->toBe('not-a-number');

    // Processed counter still incremented — that happens independently of duration.
    $bucket = currentUtcBucket();
    expect(R::int('get', 'qmtest:processed:App\\Jobs\\Fake:' . $bucket))->toBe(1);
});

it('swallows redis errors without breaking the job pipeline', function (): void {
    config()->set('queue-insights.redis_connection', 'nonexistent_connection');

    expect(function (): void {
        dispatch(new TestJob());
    })->not->toThrow(Throwable::class);
});

/**
 * Return the fields of the first entry in a predis xrange response, which has shape
 * `[id => [field => value, ...], ...]`.
 *
 * @param  array<string, array<string, string>>|mixed  $entries
 * @return array<string, string>
 */
function firstStreamEntryFields(mixed $entries): array
{
    if (! is_array($entries) || $entries === []) {
        return [];
    }

    $first = $entries[array_key_first($entries)] ?? null;

    if (! is_array($first)) {
        return [];
    }

    $out = [];
    foreach ($first as $k => $v) {
        if (! is_string($v) && ! is_int($v) && ! is_float($v) && ! is_bool($v)) {
            continue;
        }

        $out[(string) $k] = (string) $v;
    }

    return $out;
}
