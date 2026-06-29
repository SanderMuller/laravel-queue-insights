<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications;

use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\LogChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\SentryChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\SlackWebhookChannel;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\SentryAvailability;
use Throwable;

/**
 * Single notification class fired per Issue. Uses Laravel's notification
 * system so host apps can:
 *
 *   - extend `via()` to add channels (Discord/Teams/PagerDuty/Telegram/SMS)
 *     by installing the matching `laravel-notification-channels/*` package
 *     and registering the channel class on their override of this notification
 *   - override `toMail()` / `toSlack()` to reshape payloads for their tooling
 *   - swap the default `QueueInsightsNotifiable` (read by `IssueDispatcher`)
 *     for a custom notifiable that routes to alternate destinations
 *
 * Mirrors the pattern used by spatie/laravel-backup,
 * spatie/laravel-uptime-monitor, and laravel/horizon. See research notes in
 * specs/alerting.md.
 */
class QueueAlertNotification extends Notification
{
    public function __construct(public readonly Issue $issue) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $root = $this->issue->channelConfigRoot();
        $channels = [];

        if (Config::bool("{$root}.log.enabled", $root === 'alerts.channels')) {
            $channels[] = LogChannel::class;
        }

        if (Config::bool("{$root}.mail.enabled", false) && $this->mailAvailable()) {
            $channels[] = 'mail';
        }

        if (Config::bool("{$root}.slack.enabled", false) && $this->httpAvailable()) {
            $channels[] = SlackWebhookChannel::class;
        }

        if (Config::bool("{$root}.sentry.enabled", false) && $this->sentryAvailable()) {
            $channels[] = SentryChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $target = $this->issueTarget();

        $environment = app()->environment();

        $subject = "[Queue Insights][{$environment}] {$this->issue->severity->value}: {$this->issue->rule} on {$target}";

        $message = (new MailMessage())
            ->subject($subject)
            ->line($this->issue->title)
            ->line($this->issue->description);

        foreach ($this->issue->context as $key => $value) {
            if (is_scalar($value)) {
                $message->line(sprintf('%s: %s', $key, $this->formatScalar($value)));
            }
        }

        $runUrl = $this->runUrl();
        if ($runUrl !== null) {
            $message->action('Open run', $runUrl);
        }

        return $message;
    }

    /**
     * Slack Block Kit payload. Returned as a plain array; the
     * SlackWebhookChannel POSTs it to the configured webhook URL. Falls back
     * to `text` if Block Kit is unsupported by the receiver.
     *
     * @return array<string, mixed>
     */
    public function toSlack(object $notifiable): array
    {
        $colour = $this->issue->severity === AlertSeverity::Critical ? '#dc2626' : '#f59e0b';
        $target = $this->issueTarget();
        $environment = app()->environment();

        $fields = [
            ['title' => 'Rule', 'value' => $this->issue->rule, 'short' => true],
            ['title' => 'Severity', 'value' => $this->issue->severity->value, 'short' => true],
            ['title' => 'Target', 'value' => $target, 'short' => false],
        ];

        foreach ($this->issue->context as $key => $value) {
            if (is_scalar($value)) {
                $fields[] = ['title' => (string) $key, 'value' => $this->formatScalar($value), 'short' => true];
            }
        }

        $runUrl = $this->runUrl();
        if ($runUrl !== null) {
            $fields[] = ['title' => 'Run URL', 'value' => $runUrl, 'short' => false];
        }

        return [
            'text' => "[{$environment}][{$this->issue->severity->value}] {$this->issueIcon()} {$this->issue->title}",
            'attachments' => [[
                'color' => $colour,
                'title' => $this->issue->title,
                'text' => $this->issue->description,
                'fields' => $fields,
                'ts' => $this->issue->detectedAt,
            ]],
        ];
    }

    /**
     * Sentry capture descriptor. Returned as a plain array so it stays pure +
     * host-overridable (no SDK calls here); `SentryChannel` translates it into
     * `withScope` + `captureMessage`. The fingerprint mirrors the cooldown-key
     * shape (`Issue::cooldownKeySuffix`) so Sentry groups one issue per
     * (rule, target) instead of a new issue per snapshot tick.
     *
     * @return array{
     *   level: 'warning'|'error',
     *   message: string,
     *   fingerprint: list<string>,
     *   tags: array<string, string>,
     *   extra: array<string, mixed>,
     * }
     */
    public function toSentry(object $notifiable): array
    {
        $target = $this->issueTarget();

        $tags = array_filter([
            'queue_insights.rule' => $this->issue->rule,
            'queue_insights.severity' => $this->issue->severity->value,
            'queue_insights.connection' => $this->issue->connection,
            'queue_insights.queue' => $this->issue->queue,
            'queue_insights.job_class' => $this->issue->jobClass ?? '',
        ], static fn (string $value): bool => $value !== '');

        return [
            'level' => $this->issue->severity === AlertSeverity::Critical ? 'error' : 'warning',
            'message' => "[Queue Insights] {$this->issue->severity->value}: {$this->issue->title}",
            'fingerprint' => ['queue-insights', $this->issue->rule, $target],
            'tags' => $tags,
            'extra' => $this->issue->context + ['detected_at' => $this->issue->detectedAt],
        ];
    }

    /**
     * Resolve the target identifier shown in subject lines + Slack title.
     * Priority matches `cooldownKeySuffix`: taskKey → jobClass → connection:queue.
     *
     * For scheduler-scoped issues the raw `taskKey` is a SHA256 — operators
     * read mail/Slack, so prefer the human-readable label written into context
     * by `ScheduledTaskLabel`. Falls back to the bare `taskKey` when the
     * dispatcher could not resolve a label (Event introspection failed).
     */
    private function issueTarget(): string
    {
        if ($this->issue->taskKey !== null) {
            $description = $this->issue->context['task_description'] ?? null;
            if (is_string($description) && $description !== '') {
                return $description;
            }

            $command = $this->issue->context['task_command'] ?? null;
            if (is_string($command) && $command !== '') {
                return $command;
            }

            return $this->issue->taskKey;
        }

        return $this->issue->jobClass ?? "{$this->issue->connection}:{$this->issue->queue}";
    }

    /**
     * Slack-friendly leading glyph picked off the rule. Operators visually
     * triage on the icon; the rule string is structured-search territory.
     */
    private function issueIcon(): string
    {
        return match ($this->issue->rule) {
            'scheduled_task_failed' => ':rotating_light:',
            'scheduled_task_hung' => ':warning:',
            'scheduled_task_missed' => ':alarm_clock:',
            default => ':bell:',
        };
    }

    /**
     * Build the deep-link to the run modal for scheduler-scoped issues. Uses
     * the dashboard route's `s_rid` slot (`{taskKey}:{runId}`) so operators
     * land on the run-modal when the run is still in retention, or the
     * mount-time fail-soft "Expired" empty state when aged out.
     */
    private function runUrl(): ?string
    {
        if (! $this->issue->isSchedulerScoped()) {
            return null;
        }

        $taskKey = $this->issue->taskKey;
        if ($taskKey === null) {
            return null;
        }

        $runId = $this->issue->context['run_id'] ?? null;
        $sRid = is_string($runId) && $runId !== '' ? "{$taskKey}:{$runId}" : null;

        try {
            $base = URL::route('queue-insights.dashboard');
        } catch (Throwable) {
            return null;
        }

        if ($sRid === null) {
            // missed runs have no runId — link to the task slot instead.
            return $base . '?s_tk=' . rawurlencode($taskKey) . '#qi-schedule';
        }

        return $base . '?s_rid=' . rawurlencode($sRid) . '#qi-schedule';
    }

    private function mailAvailable(): bool
    {
        return app()->bound('mail.manager');
    }

    private function httpAvailable(): bool
    {
        return app()->bound(Factory::class);
    }

    private function sentryAvailable(): bool
    {
        return SentryAvailability::available();
    }

    /**
     * Render a scalar context value for human-facing channels (mail, Slack).
     * Floats round to 2dp so a `ratio` of 0.13235294117647 doesn't dump its
     * full default precision. Ints + bools + strings pass through. Context
     * itself is left raw so host event listeners (typed events) keep their
     * full-precision floats.
     */
    private function formatScalar(int|float|string|bool $value): string
    {
        if (is_float($value)) {
            return number_format($value, 2);
        }

        return (string) $value;
    }
}
