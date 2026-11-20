<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Exceptions\ListenerFailedException;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Support\ListenerFailureReporter;

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
