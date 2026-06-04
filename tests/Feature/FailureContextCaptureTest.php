<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Events\JobFailedAlert;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Support\FailureContextStore;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    Context::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.capture.redact_keys', ['.*password.*', '.*secret.*', '.*token.*', '.*api[_-]?key.*', '.*authorization.*', '.*credential.*']);
    config()->set('queue-insights.failure_context.enabled', true);
    config()->set('queue-insights.failure_context.capture_app_context', true);
    config()->set('queue-insights.failure_context.capture_environment', true);
});

afterEach(fn () => Context::flush());

function failContextJob(string $uuid): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\CtxJob');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\CtxJob']);

    return $job;
}

it('writes the sanitized context + environment to qi:failure-ctx:{uuid}', function (): void {
    Context::add(['user_id' => 7, 'tenant' => 'acme', 'password' => 'hunter2']);

    resolve(RecordJobFailed::class)->handle(
        new JobFailed('redis', failContextJob('ctx-uuid-1'), new RuntimeException('boom'))
    );

    $stored = (new FailureContextStore())->read('ctx-uuid-1');

    expect($stored['app_context']['user_id'])->toBe(7)
        ->and($stored['app_context']['tenant'])->toBe('acme')
        ->and($stored['app_context']['password'])->toBe('[REDACTED]')
        ->and($stored['environment']['host'])->toBeString()
        ->and($stored['environment']['env'])->toBe('testing');
});

it('stores nothing when failure_context is disabled', function (): void {
    config()->set('queue-insights.failure_context.enabled', false);
    Context::add(['user_id' => 7]);

    resolve(RecordJobFailed::class)->handle(
        new JobFailed('redis', failContextJob('ctx-uuid-2'), new RuntimeException('boom'))
    );

    expect((new FailureContextStore())->read('ctx-uuid-2'))->toBe(['app_context' => [], 'environment' => []]);
});

it('carries the failure context on the JobFailedAlert event', function (): void {
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.cooldown_seconds', 900);
    config()->set('queue-insights.alerts.rules.job_failed.enabled', true);

    Event::fake([JobFailedAlert::class]);
    Context::add(['user_id' => 7]);

    resolve(RecordJobFailed::class)->handle(
        new JobFailed('redis', failContextJob('ctx-uuid-3'), new RuntimeException('boom'))
    );

    Event::assertDispatched(
        JobFailedAlert::class,
        fn (JobFailedAlert $e): bool => is_array($e->context)
            && is_array($e->context['app_context'] ?? null)
            && ($e->context['app_context']['user_id'] ?? null) === 7,
    );
});
