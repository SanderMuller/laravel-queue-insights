<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications;

use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\LogChannel;
use SanderMuller\QueueInsights\Alerts\Notifications\Channels\SlackWebhookChannel;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;

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
        $channels = [];

        if (Config::bool('alerts.channels.log.enabled', true)) {
            $channels[] = LogChannel::class;
        }

        if (Config::bool('alerts.channels.mail.enabled', false) && $this->mailAvailable()) {
            $channels[] = 'mail';
        }

        if (Config::bool('alerts.channels.slack.enabled', false) && $this->httpAvailable()) {
            $channels[] = SlackWebhookChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $target = $this->issue->jobClass ?? "{$this->issue->connection}:{$this->issue->queue}";

        $subject = "[Queue Insights] {$this->issue->severity->value}: {$this->issue->rule} on {$target}";

        $message = (new MailMessage())
            ->subject($subject)
            ->line($this->issue->title)
            ->line($this->issue->description);

        foreach ($this->issue->context as $key => $value) {
            if (is_scalar($value)) {
                $message->line(sprintf('%s: %s', $key, $this->formatScalar($value)));
            }
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
        $target = $this->issue->jobClass ?? "{$this->issue->connection}:{$this->issue->queue}";

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

        return [
            'text' => "[{$this->issue->severity->value}] {$this->issue->title}",
            'attachments' => [[
                'color' => $colour,
                'title' => $this->issue->title,
                'text' => $this->issue->description,
                'fields' => $fields,
                'ts' => $this->issue->detectedAt,
            ]],
        ];
    }

    private function mailAvailable(): bool
    {
        return app()->bound('mail.manager');
    }

    private function httpAvailable(): bool
    {
        return app()->bound(Factory::class);
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
