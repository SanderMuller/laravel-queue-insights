<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Events\JobFailedAlert;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.cooldown_seconds', 900);
    config()->set('queue-insights.alerts.rules.job_failed.enabled', true);
    config()->set('queue-insights.alerts.rules.job_failed.notify', true);
    config()->set('queue-insights.alerts.rules.job_failed.severity', 'warning');
});

function jobFailedCooldownExists(string $class): bool
{
    return Redis::connection('default')->command('exists', ["qmtest:alert:cooldown:job_failed:class:{$class}"]) === 1;
}

it('fires JobFailedAlert with the live Throwable, notifies, and burns the cooldown', function (): void {
    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    $exception = new RuntimeException('boom');

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', $exception);

    Event::assertDispatched(
        JobFailedAlert::class,
        fn (JobFailedAlert $e): bool => $e->jobClass === 'App\\Jobs\\Boom'
            && $e->connection === 'redis'
            && $e->queue === 'default'
            && $e->uuid === 'uuid-1'
            && $e->exception === $exception
            && $e->severity === 'warning',
    );

    // The rich rule/class/severity assertions live on the JobFailedAlert
    // event above; here we only confirm the package notification was sent
    // (the Issue DTO is @internal, so we don't reach into it from a test).
    Notification::assertSentTo(new QueueInsightsNotifiable(), QueueAlertNotification::class);

    expect(jobFailedCooldownExists('App\\Jobs\\Boom'))->toBeTrue();
});

it('suppresses a second failure of the same class within the cooldown window', function (): void {
    Event::fake([JobFailedAlert::class]);

    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('a'));
    $dispatcher->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-2', new RuntimeException('b'));

    Event::assertDispatchedTimes(JobFailedAlert::class, 1);
});

it('still fires for a different class within the first class cooldown (per-class key)', function (): void {
    Event::fake([JobFailedAlert::class]);

    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('a'));
    $dispatcher->dispatchJobFailed('App\\Jobs\\Other', 'redis', 'default', 'uuid-2', new RuntimeException('b'));

    Event::assertDispatchedTimes(JobFailedAlert::class, 2);
});

it('suppresses a silenced class — no event, no notification, no cooldown burned', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Muted']);

    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Muted', 'redis', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertNotDispatched(JobFailedAlert::class);
    Notification::assertNothingSent();
    expect(jobFailedCooldownExists('App\\Jobs\\Muted'))->toBeFalse();
});

it('with notify=false fires the event but sends no package notification (event-only mode)', function (): void {
    config()->set('queue-insights.alerts.rules.job_failed.notify', false);

    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertDispatched(JobFailedAlert::class);
    Notification::assertNothingSent();
    // Cooldown still burned so the event itself stays throttled.
    expect(jobFailedCooldownExists('App\\Jobs\\Boom'))->toBeTrue();
});

it('is a no-op when the rule is disabled', function (): void {
    config()->set('queue-insights.alerts.rules.job_failed.enabled', false);

    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertNotDispatched(JobFailedAlert::class);
    Notification::assertNothingSent();
    expect(jobFailedCooldownExists('App\\Jobs\\Boom'))->toBeFalse();
});

it('is a no-op when alerts.enabled is false even if the rule is enabled', function (): void {
    config()->set('queue-insights.alerts.enabled', false);

    Event::fake([JobFailedAlert::class]);

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertNotDispatched(JobFailedAlert::class);
});

it('honours a critical severity from config', function (): void {
    config()->set('queue-insights.alerts.rules.job_failed.severity', 'critical');

    Event::fake([JobFailedAlert::class]);

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'redis', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertDispatched(
        JobFailedAlert::class,
        fn (JobFailedAlert $e): bool => $e->severity === 'critical',
    );
});

it('fires for a non-redis queue connection (driver-agnostic, no snapshot needed)', function (): void {
    Event::fake([JobFailedAlert::class]);

    resolve(IssueDispatcher::class)->dispatchJobFailed('App\\Jobs\\Boom', 'database', 'default', 'uuid-1', new RuntimeException('x'));

    Event::assertDispatched(
        JobFailedAlert::class,
        fn (JobFailedAlert $e): bool => $e->connection === 'database',
    );
    // Cooldown key is class-scoped (connection-agnostic) — see §"Cooldown scope".
    expect(jobFailedCooldownExists('App\\Jobs\\Boom'))->toBeTrue();
});

it('keys the cooldown on a synthetic closure label', function (): void {
    Event::fake([JobFailedAlert::class]);

    resolve(IssueDispatcher::class)->dispatchJobFailed('Closure@redis:default', 'redis', 'default', null, new RuntimeException('x'));

    expect(jobFailedCooldownExists('Closure@redis:default'))->toBeTrue();
});

it('dispatches the alert end-to-end through the RecordJobFailed listener — event and notification', function (): void {
    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('listener-uuid');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ListenerBoom');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\ListenerBoom']);

    $event = new JobFailed('redis', $job, new RuntimeException('listener boom'));

    resolve(RecordJobFailed::class)->handle($event);

    Event::assertDispatched(
        JobFailedAlert::class,
        fn (JobFailedAlert $e): bool => $e->jobClass === 'App\\Jobs\\ListenerBoom'
            && $e->exception instanceof RuntimeException,
    );

    // The worker hot-path must actually drive the synchronous notification
    // (notify=true by default), not merely emit the event.
    Notification::assertSentTo(new QueueInsightsNotifiable(), QueueAlertNotification::class);
});

it('does NOT notify through the listener when notify=false (event-only worker path)', function (): void {
    config()->set('queue-insights.alerts.rules.job_failed.notify', false);

    Event::fake([JobFailedAlert::class]);
    Notification::fake();

    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('listener-uuid-2');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ListenerBoom');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\ListenerBoom']);

    resolve(RecordJobFailed::class)->handle(new JobFailed('redis', $job, new RuntimeException('boom')));

    Event::assertDispatched(JobFailedAlert::class);
    Notification::assertNothingSent();
});
