<?php declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Events\JobClassFailureRateExceeded;
use SanderMuller\QueueInsights\Events\JobClassP95Exceeded;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\SilencedAlertingHelpers;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.cooldown_seconds', 900);
});

it('IssueDispatcher::handle short-circuits silenced class-scoped issues before cooldown is acquired', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Muted']);

    Event::fake([JobClassFailureRateExceeded::class]);

    SilencedAlertingHelpers::callDispatcherHandle(
        resolve(IssueDispatcher::class),
        SilencedAlertingHelpers::fixtureClassScopedIssue('App\\Jobs\\Muted'),
    );

    Event::assertNotDispatched(JobClassFailureRateExceeded::class);
    expect(Redis::connection('default')->command('exists', ['qmtest:alert:cooldown:failure_rate:class:App\\Jobs\\Muted']))->toBe(0);
});

it('IssueDispatcher::handle still fires for non-silenced class-scoped issues', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Other']);

    Event::fake([JobClassFailureRateExceeded::class]);

    SilencedAlertingHelpers::callDispatcherHandle(
        resolve(IssueDispatcher::class),
        SilencedAlertingHelpers::fixtureClassScopedIssue('App\\Jobs\\Loud'),
    );

    Event::assertDispatched(JobClassFailureRateExceeded::class);
    expect(Redis::connection('default')->command('exists', ['qmtest:alert:cooldown:failure_rate:class:App\\Jobs\\Loud']))->toBe(1);
});

it('IssueDispatcher::handle does NOT silence slow_p95 — silencing is failure-noise scoped only (spec §1)', function (): void {
    // Codex review #1: the dispatcher's belt-and-suspenders guard must
    // only fire for failure_rate. slow_p95 sets jobClass too but spec §1
    // explicitly keeps it unchanged ("silence is failure noise, not perf").
    // A silenced class with a slow_p95 issue must still fire its event +
    // burn its cooldown.
    config()->set('queue-insights.silenced', ['App\\Jobs\\KnownFlaky']);

    Event::fake([JobClassP95Exceeded::class]);

    SilencedAlertingHelpers::callDispatcherHandle(
        resolve(IssueDispatcher::class),
        SilencedAlertingHelpers::fixtureRuleScopedIssue('slow_p95', 'App\\Jobs\\KnownFlaky'),
    );

    Event::assertDispatched(JobClassP95Exceeded::class);
    expect(Redis::connection('default')->command('exists', ['qmtest:alert:cooldown:slow_p95:class:App\\Jobs\\KnownFlaky']))->toBe(1);
});
