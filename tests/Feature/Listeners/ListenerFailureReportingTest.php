<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Exceptions\ListenerFailedException;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Support\ListenerFailureReporter;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    config()->set('queue-insights.enabled', true);
    Exceptions::fake();
});

function failingProcessingEvent(): JobProcessing
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('uuid-1');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    return new JobProcessing(connectionName: 'redis', job: $job);
}

it('reports a listener failure to the exception handler, wrapping the original', function (): void {
    // Redis connection that does not exist — Redis::connection() throws.
    config()->set('queue-insights.redis_connection', 'missing-connection');

    (new RecordJobProcessing())->handle(failingProcessingEvent());

    Exceptions::assertReported(fn (ListenerFailedException $e): bool => $e->listener === 'RecordJobProcessing'
        && $e->getPrevious() instanceof Throwable
        && str_contains($e->getMessage(), 'RecordJobProcessing'));
});

it('reports an error reply from inside the listener pipeline', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    // A string where the pending zset belongs makes the pipelined ZADD fail
    // with WRONGTYPE. phpredis returns that as `false` without throwing.
    R::conn()->command('set', ['qm:testing:pending-zset:redis:default', 'not-a-zset']);

    $payload = json_encode(['uuid' => 'uuid-wrongtype', 'displayName' => 'App\\Jobs\\Example']);

    (new RecordJobQueued())->handle(new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'driver-id',
        job: (object) ['displayName' => 'App\\Jobs\\Example'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    ));

    Exceptions::assertReported(fn (ListenerFailedException $e): bool => $e->listener === 'RecordJobQueued'
        && str_contains($e->getMessage(), 'WRONGTYPE'));
});

it('reports at most once per listener per interval', function (): void {
    config()->set('queue-insights.listener_failures.report_interval_seconds', 300);
    $reporter = resolve(ListenerFailureReporter::class);

    $reporter->report('RecordJobProcessing', new RuntimeException('a'));
    $reporter->report('RecordJobProcessing', new RuntimeException('b'));
    // A different listener has its own budget.
    $reporter->report('RecordJobProcessed', new RuntimeException('c'));

    Exceptions::assertReportedCount(2);

    Date::setTestNow(Date::now()->addSeconds(301));
    $reporter->report('RecordJobProcessing', new RuntimeException('d'));

    Exceptions::assertReportedCount(3);
});

it('shares the throttle across worker processes through the cache store', function (): void {
    config()->set('cache.default', 'array');

    // A fresh singleton stands in for a second worker process.
    resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', new RuntimeException('a'));
    app()->forgetInstance(ListenerFailureReporter::class);
    resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', new RuntimeException('b'));

    Exceptions::assertReportedCount(1);
});

it('falls back to the per-process throttle when the cache store is unavailable', function (): void {
    config()->set('queue-insights.listener_failures.cache_store', 'missing-store');

    resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', new RuntimeException('a'));
    app()->forgetInstance(ListenerFailureReporter::class);
    resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', new RuntimeException('b'));

    Exceptions::assertReportedCount(2);
});

it('does not report when listener failure reporting is disabled', function (): void {
    config()->set('queue-insights.listener_failures.report', false);

    resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', new RuntimeException('a'));

    Exceptions::assertNothingReported();
});
