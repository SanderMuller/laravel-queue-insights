<?php declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\LogChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\SlackWebhookChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
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
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
});

function depthFiringDriver(int $depth): QueueSnapshotDriver
{
    return new readonly class ($depth) implements QueueSnapshotDriver {
        public function __construct(private int $d) {}

        public function depth(string $queue): int
        {
            return $this->d;
        }

        public function inFlight(string $queue): ?int
        {
            return null;
        }

        public function delayed(string $queue): ?int
        {
            return null;
        }

        public function canonicalKey(string $queue): string
        {
            return CanonicalQueueKey::from($queue);
        }
    };
}

function configureFiringDepth(): void
{
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthFiringDriver(5_000));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1_000, 'severity' => 'critical'],
    ]);
}

it('dispatches a QueueAlertNotification through the bound Notifiable', function (): void {
    configureFiringDepth();

    Notification::fake();

    Artisan::call('queue-insights:snapshot');

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $n): bool => $n->issue->rule === 'depth'
            && $n->issue->severity === AlertSeverity::Critical
            && $n->issue->context['depth'] === 5_000,
    );
});

it('via() returns log channel by default and excludes mail/slack when disabled', function (): void {
    config()->set('queue-insights.alerts.channels.log.enabled', true);
    config()->set('queue-insights.alerts.channels.mail.enabled', false);
    config()->set('queue-insights.alerts.channels.slack.enabled', false);

    $issue = makeDepthIssue();
    $notification = new QueueAlertNotification($issue);

    expect($notification->via(new QueueInsightsNotifiable()))->toBe([LogChannel::class]);
});

it('via() includes mail when enabled and the mail manager is bound', function (): void {
    config()->set('queue-insights.alerts.channels.log.enabled', false);
    config()->set('queue-insights.alerts.channels.mail.enabled', true);
    config()->set('queue-insights.alerts.channels.slack.enabled', false);

    $notification = new QueueAlertNotification(makeDepthIssue());

    expect($notification->via(new QueueInsightsNotifiable()))->toBe(['mail']);
});

it('via() includes slack-webhook channel when enabled and Http factory is bound', function (): void {
    config()->set('queue-insights.alerts.channels.log.enabled', false);
    config()->set('queue-insights.alerts.channels.mail.enabled', false);
    config()->set('queue-insights.alerts.channels.slack.enabled', true);

    $notification = new QueueAlertNotification(makeDepthIssue());

    expect($notification->via(new QueueInsightsNotifiable()))->toBe([SlackWebhookChannel::class]);
});

it('toMail() returns a MailMessage with the [Queue Insights] subject prefix', function (): void {
    $message = (new QueueAlertNotification(makeDepthIssue()))->toMail(new QueueInsightsNotifiable());

    expect($message->subject)->toContain('[Queue Insights]')
        ->toContain('critical')
        ->toContain('depth');
});

it('toSlack() returns a Block-Kit-shaped payload with severity colour', function (): void {
    $payload = (new QueueAlertNotification(makeDepthIssue()))->toSlack(new QueueInsightsNotifiable());

    expect($payload)->toHaveKey('text')
        ->and($payload['attachments'][0]['color'])->toBe('#dc2626'); // critical = red
});

it('LogChannel writes a structured log line at the configured level', function (): void {
    config()->set('queue-insights.alerts.channels.log.level', 'error');

    Log::shouldReceive('log')
        ->once()
        ->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error'
            && str_contains($message, 'queue-insights alert')
            && ($context['rule'] ?? null) === 'depth'
            && ($context['severity'] ?? null) === 'critical');

    (new LogChannel())->send(new QueueInsightsNotifiable(), new QueueAlertNotification(makeDepthIssue()));
});

it('SlackWebhookChannel POSTs the Block Kit payload to the configured webhook URL', function (): void {
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/T/B/X');

    Http::fake();

    (new SlackWebhookChannel())->send(
        new QueueInsightsNotifiable(),
        new QueueAlertNotification(makeDepthIssue()),
    );

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://hooks.example.com/T/B/X') {
            return false;
        }

        $data = $request->data();
        if (! is_array($data)) {
            return false;
        }

        $text = $data['text'] ?? null;
        if (! is_string($text) || ! str_contains($text, 'critical')) {
            return false;
        }

        return isset($data['attachments'][0]['fields']);
    });
});

it('SlackWebhookChannel falls back to text-only payload when the receiver rejects Block Kit', function (): void {
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/fallback');

    $first = true;
    Http::fake(function () use (&$first) {
        if ($first) {
            $first = false;

            return Http::response('rejected', 400);
        }

        return Http::response('ok', 200);
    });

    (new SlackWebhookChannel())->send(
        new QueueInsightsNotifiable(),
        new QueueAlertNotification(makeDepthIssue()),
    );

    // Two requests: first Block Kit (rejected), second plain `text`.
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request, Response $response): bool {
        if ($response->status() !== 200) {
            return false;
        }

        $data = $request->data();

        return is_array($data) && array_keys($data) === ['text'];
    });
});

it('SlackWebhookChannel skips when no webhook URL routed', function (): void {
    config()->set('queue-insights.alerts.channels.slack.webhook_url', '');

    Http::fake();

    (new SlackWebhookChannel())->send(
        new AnonymousNotifiable(),
        new QueueAlertNotification(makeDepthIssue()),
    );

    Http::assertNothingSent();
});

it('via() excludes mail when mail.manager is unbound', function (): void {
    // Best-effort: Testbench loads the full framework which always binds
    // mail.manager. The runtime feature gate is exercised in production
    // paths; this test only meaningfully runs in a stripped-down container.
    if (app()->bound('mail.manager')) {
        $this->markTestSkipped('mail.manager always bound under Testbench full framework');
    }

    config()->set('queue-insights.alerts.channels.log.enabled', false);
    config()->set('queue-insights.alerts.channels.mail.enabled', true);

    expect((new QueueAlertNotification(makeDepthIssue()))->via(new QueueInsightsNotifiable()))
        ->toBeEmpty();
});

it('Mail::fake captures the dispatched mail when the mail channel is enabled', function (): void {
    configureFiringDepth();
    config()->set('queue-insights.alerts.channels.log.enabled', false);
    config()->set('queue-insights.alerts.channels.mail.enabled', true);
    config()->set('queue-insights.alerts.channels.mail.to', ['ops@example.com']);
    config()->set('queue-insights.alerts.channels.slack.enabled', false);

    Notification::fake();

    Artisan::call('queue-insights:snapshot');

    Notification::assertSentTo(
        new QueueInsightsNotifiable(),
        QueueAlertNotification::class,
        fn (QueueAlertNotification $_, array $channels): bool => in_array('mail', $channels, true),
    );
});

it('host can override Notifiable to route slack to a different webhook', function (): void {
    $custom = new class extends QueueInsightsNotifiable {
        public function routeNotificationForSlack(): string
        {
            return 'https://hooks.example.com/CUSTOM';
        }
    };

    app()->bind(QueueInsightsNotifiable::class, fn () => $custom);

    configureFiringDepth();
    config()->set('queue-insights.alerts.channels.log.enabled', false);
    config()->set('queue-insights.alerts.channels.slack.enabled', true);
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.example.com/DEFAULT');

    Http::fake();

    Artisan::call('queue-insights:snapshot');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.com/CUSTOM');
});

function makeDepthIssue(): Issue
{
    return new Issue(
        rule: 'depth',
        severity: AlertSeverity::Critical,
        connection: 'sqsq',
        queue: 'work',
        jobClass: null,
        title: 'Queue depth exceeded',
        description: 'Queue sqsq:work depth 5000 ≥ threshold 1000.',
        context: ['depth' => 5_000, 'threshold' => 1_000],
        detectedAt: 1_700_000_000,
    );
}
