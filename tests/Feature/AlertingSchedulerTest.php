<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\LogChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\SlackWebhookChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Events\ScheduledTaskFailed as DomainScheduledTaskFailed;
use SanderMuller\QueueInsights\Events\ScheduledTaskHung as DomainScheduledTaskHung;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.cooldown_seconds', 900);
    config()->set('queue-insights.scheduler.alerts.enabled', true);
    config()->set('queue-insights.scheduler.alerts.cooldown_seconds', 900);
    // Default queue-side log channel on so via() yields a deterministic baseline.
    config()->set('queue-insights.alerts.channels.log.enabled', true);
    config()->set('queue-insights.alerts.channels.mail.enabled', false);
    config()->set('queue-insights.alerts.channels.slack.enabled', false);
});

function fakeTask(): Event
{
    $mock = Mockery::mock(Event::class);
    if (! $mock instanceof Event) {
        throw new RuntimeException('Mockery did not produce an Event-typed mock');
    }

    return $mock;
}

function realTask(string $command, ?string $description = null, string $expression = '0 3 * * *'): Event
{
    $event = new Event(app(CacheEventMutex::class), $command);
    $event->expression = $expression;
    if ($description !== null) {
        $event->description = $description;
    }

    return $event;
}

it('dispatchScheduledTaskFailed acquires cooldown and notifies via QueueAlertNotification', function (): void {
    Notification::fake();
    EventDispatcher::fake([DomainScheduledTaskFailed::class]);

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskFailed(
        'PruneCache',
        '01HK0M00000000000000000000',
        fakeTask(),
        new RuntimeException('connection refused'),
    );

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->rule === 'scheduled_task_failed'
            && $n->issue->taskKey === 'PruneCache'
            && $n->issue->context['run_id'] === '01HK0M00000000000000000000'
            && $n->issue->context['exception_class'] === RuntimeException::class,
    );

    EventDispatcher::assertDispatched(DomainScheduledTaskFailed::class);
});

it('cooldown blocks the second dispatch for the same (rule, taskKey) within the window', function (): void {
    Notification::fake();
    EventDispatcher::fake([DomainScheduledTaskFailed::class]);

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);

    $dispatcher->dispatchScheduledTaskFailed('PruneCache', '01HK1', fakeTask(), null);
    $dispatcher->dispatchScheduledTaskFailed('PruneCache', '01HK2', fakeTask(), null);

    Notification::assertSentToTimes(new QueueInsightsNotifiable(), QueueAlertNotification::class, 1);
    EventDispatcher::assertDispatchedTimes(DomainScheduledTaskFailed::class, 1);
});

it('cooldown is per-task — different taskKeys notify independently', function (): void {
    Notification::fake();

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);

    $dispatcher->dispatchScheduledTaskHung('PruneCache', '01HKA', null, 1_700_000_000_000, 60);
    $dispatcher->dispatchScheduledTaskHung('SyncCustomers', '01HKB', null, 1_700_000_000_000, 60);

    Notification::assertSentToTimes(new QueueInsightsNotifiable(), QueueAlertNotification::class, 2);
});

it('dispatchScheduledTaskMissed populates expected_at_ms in context', function (): void {
    Notification::fake();

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('NightlyBackup', fakeTask(), 1_700_000_000_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->rule === 'scheduled_task_missed'
            && $n->issue->context['expected_at_ms'] === 1_700_000_000_000,
    );
});

it('dispatchScheduledTaskMissed enriches title and context with the resolved task label', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'", 'Export nightly reports', '0 3 * * *');

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('cc6f446a6f39', $task, 1_778_791_980_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task missed: Export nightly reports'
            && $n->issue->context['task_description'] === 'Export nightly reports'
            && $n->issue->context['task_command'] === 'php artisan reports:export'
            && $n->issue->context['task_expression'] === '0 3 * * *'
            && $n->issue->context['task_type'] === 'command'
            && $n->issue->context['task_key'] === 'cc6f446a6f39',
    );
});

it('dispatchScheduledTaskMissed falls back to the shortened command when no description is set', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'");

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('cc6f446a6f39', $task, 1_778_791_980_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task missed: php artisan reports:export',
    );
});

it('sanitises hostile task descriptions — whitespace-only collapses to command fallback', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'", "   \t  ");

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('cc6f446a6f39', $task, 1_778_791_980_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task missed: php artisan reports:export'
            && ! array_key_exists('task_description', $n->issue->context),
    );
});

it('sanitises hostile task descriptions — control characters collapse to a single line', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'", "Export\nnightly\rreports\t\u{0007}done");

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('cc6f446a6f39', $task, 1_778_791_980_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task missed: Export nightly reports done'
            && $n->issue->context['task_description'] === 'Export nightly reports done',
    );
});

it('dispatchScheduledTaskHung enriches title and context with the resolved task label', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'", 'Export nightly reports');

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskHung('cc6f446a6f39', '01HKHUNG', $task, 1_700_000_000_000, 47 * 60);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task hung: Export nightly reports'
            && $n->issue->context['task_description'] === 'Export nightly reports'
            && $n->issue->context['task_command'] === 'php artisan reports:export',
    );
});

it('dispatchScheduledTaskHung falls back to the task_key prefix when task is null (reconciler could not resolve)', function (): void {
    Notification::fake();

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskHung('cc6f446a6f39367975', '01HKHUNG', null, 1_700_000_000_000, 60);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task hung: cc6f446a6f39'
            && ! array_key_exists('task_command', $n->issue->context),
    );
});

it('dispatchScheduledTaskFailed enriches title and context with the resolved task label', function (): void {
    Notification::fake();

    $task = realTask("'/opt/Herd/bin/php' 'artisan' 'reports:export'", 'Export nightly reports');

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskFailed('cc6f446a6f39', '01HKFAIL', $task, new RuntimeException('boom'));

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task failed: Export nightly reports'
            && $n->issue->context['task_description'] === 'Export nightly reports'
            && $n->issue->context['exception_class'] === RuntimeException::class,
    );
});

it('scheduled-task dispatch falls back to a short task_key prefix when Event introspection fails', function (): void {
    Notification::fake();

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    // fakeTask() is a strict Mockery mock — mutexName() will throw inside
    // TaskSummariser, exercising the try/catch fallback in ScheduledTaskLabel.
    $dispatcher->dispatchScheduledTaskMissed('cc6f446a6f39367975e2358d', fakeTask(), 1_778_791_980_000);

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->title === 'Scheduled task missed: cc6f446a6f39'
            && ! array_key_exists('task_command', $n->issue->context),
    );
});

it('via() honours scheduler-domain channels when scheduler block is non-empty', function (): void {
    // Scheduler-only Slack override; queue-side log is default-on but the
    // scheduler issue must read its own block first.
    config()->set('queue-insights.scheduler.alerts.channels.log.enabled', false);
    config()->set('queue-insights.scheduler.alerts.channels.slack.enabled', true);
    config()->set('queue-insights.scheduler.alerts.channels.slack.webhook_url', 'https://hooks.example.com/SCHED');

    $issue = (new QueueAlertNotification(schedulerIssue()))->via(new QueueInsightsNotifiable());

    expect($issue)->toBe([SlackWebhookChannel::class]);
});

it('via() falls back to queue-side block when scheduler block is empty', function (): void {
    // Scheduler block ABSENT — pulled from a fresh config(). The queue-side
    // log channel default-on should still drive logging for scheduler issues.
    config()->set('queue-insights.scheduler.alerts.channels', []);
    config()->set('queue-insights.alerts.channels.log.enabled', true);

    $channels = (new QueueAlertNotification(schedulerIssue()))->via(new QueueInsightsNotifiable());

    expect($channels)->toBe([LogChannel::class]);
});

it('routeNotificationForSlack prefers scheduler block when scheduler slack is explicitly enabled', function (): void {
    // Enabled flag is the override signal — populating just webhook_url
    // without enabling must not silently page the scheduler destination.
    config()->set('queue-insights.scheduler.alerts.channels.slack.enabled', true);
    config()->set('queue-insights.scheduler.alerts.channels.slack.webhook_url', 'https://hooks.example.com/SCHED');
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/QUEUE');

    $notifiable = new QueueInsightsNotifiable();
    $notification = new QueueAlertNotification(schedulerIssue());

    expect($notifiable->routeNotificationForSlack($notification))->toBe('https://hooks.example.com/SCHED');
});

it('routeNotificationForSlack falls back to queue-side webhook when scheduler block has no channel enabled (operator must opt in)', function (): void {
    // webhook_url populated but enabled flag NOT flipped — must NOT route
    // to the scheduler webhook because operator hasn't opted in. Mirrors
    // the via() decision so channel selection + destination cannot diverge.
    config()->set('queue-insights.scheduler.alerts.channels.slack.enabled', false);
    config()->set('queue-insights.scheduler.alerts.channels.slack.webhook_url', 'https://hooks.example.com/SCHED');
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/QUEUE');

    $notifiable = new QueueInsightsNotifiable();
    $notification = new QueueAlertNotification(schedulerIssue());

    expect($notifiable->routeNotificationForSlack($notification))->toBe('https://hooks.example.com/QUEUE');
});

it('routeNotificationForSlack reads queue-side block on queue-scoped notifications', function (): void {
    config()->set('queue-insights.scheduler.alerts.channels.slack.webhook_url', 'https://hooks.example.com/SCHED');
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/QUEUE');

    $notifiable = new QueueInsightsNotifiable();
    $notification = new QueueAlertNotification(queueIssue());

    expect($notifiable->routeNotificationForSlack($notification))->toBe('https://hooks.example.com/QUEUE');
});

it('routeNotificationForMail prefers scheduler-block recipients only when scheduler mail is explicitly enabled', function (): void {
    config()->set('queue-insights.scheduler.alerts.channels.mail.enabled', true);
    config()->set('queue-insights.scheduler.alerts.channels.mail.to', ['cron@example.com']);
    config()->set('queue-insights.alerts.channels.mail.to', ['ops@example.com']);

    $notifiable = new QueueInsightsNotifiable();
    $notification = new QueueAlertNotification(schedulerIssue());

    expect($notifiable->routeNotificationForMail($notification))->toBe(['cron@example.com']);
});

it('Slack payload carries a run_url query slot pointing at s_rid', function (): void {
    Route::get('/queue-insights', fn () => 'ok')->name('queue-insights.dashboard');

    $issue = schedulerIssue();
    $payload = (new QueueAlertNotification($issue))->toSlack(new QueueInsightsNotifiable());

    $runUrlField = findField($payload, 'Run URL');

    expect($runUrlField)->not->toBeNull();
    assert($runUrlField !== null);
    expect($runUrlField['value'])->toContain('s_rid=PruneCache%3A01HKRUN');
});

it('Slack payload links to the task slot for missed runs (no run id)', function (): void {
    Route::get('/queue-insights', fn () => 'ok')->name('queue-insights.dashboard');

    $issue = schedulerIssue(rule: 'scheduled_task_missed', context: ['expected_at_ms' => 1_700_000_000_000]);
    $payload = (new QueueAlertNotification($issue))->toSlack(new QueueInsightsNotifiable());

    $runUrlField = findField($payload, 'Run URL');

    expect($runUrlField)->not->toBeNull();
    assert($runUrlField !== null);
    expect($runUrlField['value'])->toContain('s_tk=PruneCache')
        ->and($runUrlField['value'])->not->toContain('s_rid=');
});

it('mail subject + Slack title prefer the human task label over the opaque task_key', function (): void {
    $issue = schedulerIssue(
        rule: 'scheduled_task_missed',
        context: [
            'expected_at_ms' => 1_700_000_000_000,
            'task_description' => 'Export nightly reports',
            'task_command' => 'php artisan reports:export',
        ],
    );

    $notification = new QueueAlertNotification($issue);
    $mail = $notification->toMail(new QueueInsightsNotifiable());
    $slack = $notification->toSlack(new QueueInsightsNotifiable());

    expect($mail->subject)->toBe('[Queue Insights] critical: scheduled_task_missed on Export nightly reports');

    $targetField = findField($slack, 'Target');
    expect($targetField)->not->toBeNull();
    assert($targetField !== null);
    expect($targetField['value'])->toBe('Export nightly reports');
});

it('mail subject falls back to task_command when no description is set', function (): void {
    $issue = schedulerIssue(
        rule: 'scheduled_task_missed',
        context: [
            'expected_at_ms' => 1_700_000_000_000,
            'task_command' => 'php artisan reports:export',
        ],
    );

    $mail = (new QueueAlertNotification($issue))->toMail(new QueueInsightsNotifiable());

    expect($mail->subject)->toBe('[Queue Insights] critical: scheduled_task_missed on php artisan reports:export');
});

it('mail subject falls back to the bare task_key when neither label is in context', function (): void {
    $issue = schedulerIssue(
        rule: 'scheduled_task_missed',
        context: ['expected_at_ms' => 1_700_000_000_000],
    );

    $mail = (new QueueAlertNotification($issue))->toMail(new QueueInsightsNotifiable());

    expect($mail->subject)->toBe('[Queue Insights] critical: scheduled_task_missed on PruneCache');
});

/**
 * Walk a Slack payload's attachment fields list and return the entry whose
 * `title` matches `$title`, or null when missing. Pure array traversal —
 * avoids `collect()` so PHPStan doesn't have to resolve template params.
 *
 * @param  array<string, mixed>  $payload
 * @return array{title: string, value: string, short: bool}|null
 */
function findField(array $payload, string $title): ?array
{
    $attachments = $payload['attachments'] ?? [];
    if (! is_array($attachments) || ! isset($attachments[0]) || ! is_array($attachments[0])) {
        return null;
    }

    $fields = $attachments[0]['fields'] ?? [];
    if (! is_array($fields)) {
        return null;
    }

    foreach ($fields as $field) {
        if (! is_array($field)) {
            continue;
        }

        if (($field['title'] ?? null) === $title
            && is_string($field['value'] ?? null)) {
            /** @var array{title: string, value: string, short: bool} $cast */
            $cast = $field;

            return $cast;
        }
    }

    return null;
}

it('the typed event still fires alongside the notification path (Phase 7b backwards-compat)', function (): void {
    Notification::fake();
    EventDispatcher::fake([DomainScheduledTaskHung::class]);

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskHung('NightlyBackup', '01HKHUNG', null, 1_700_000_000_000, 47 * 60);

    EventDispatcher::assertDispatched(DomainScheduledTaskHung::class);
    Notification::assertSentTo(new QueueInsightsNotifiable(), QueueAlertNotification::class);
});

it('skips notifications but still fires the typed event when alerts.enabled is false (preserves pre-7b behaviour)', function (): void {
    config()->set('queue-insights.alerts.enabled', false);

    Notification::fake();
    EventDispatcher::fake([DomainScheduledTaskHung::class]);

    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskHung('NightlyBackup', '01HKHUNG', null, 1_700_000_000_000, 60);

    EventDispatcher::assertDispatched(DomainScheduledTaskHung::class);
    Notification::assertNotSentTo(new QueueInsightsNotifiable(), QueueAlertNotification::class);
});

it('writes cooldown under the new alert:cooldown:scheduled_task_*:task: namespace', function (): void {
    /** @var IssueDispatcher $dispatcher */
    $dispatcher = resolve(IssueDispatcher::class);
    $dispatcher->dispatchScheduledTaskMissed('NightlyBackup', fakeTask(), 1_700_000_000_000);

    $key = 'qmtest:alert:cooldown:scheduled_task_missed:task:NightlyBackup';

    /** @var Connection $redis */
    $redis = Redis::connection('default');
    expect($redis->command('exists', [$key]))->toBeTruthy();
});

/**
 * @param  array<string, mixed>  $context
 */
function schedulerIssue(string $rule = 'scheduled_task_failed', array $context = ['run_id' => '01HKRUN']): Issue
{
    return new Issue(
        rule: $rule,
        severity: AlertSeverity::Critical,
        connection: '',
        queue: '',
        jobClass: null,
        title: 'Scheduled task issue',
        description: 'test',
        context: $context,
        detectedAt: 1_700_000_000,
        taskKey: 'PruneCache',
    );
}

function queueIssue(): Issue
{
    return new Issue(
        rule: 'depth',
        severity: AlertSeverity::Critical,
        connection: 'redis',
        queue: 'default',
        jobClass: null,
        title: 'depth',
        description: 'test',
        context: [],
        detectedAt: 1_700_000_000,
    );
}
