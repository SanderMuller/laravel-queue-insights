<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use Throwable;

/**
 * Custom Notification channel posting a Slack incoming-webhook payload.
 * Used directly by `QueueAlertNotification::via()` — host apps adopting the
 * official `laravel/slack-notification-channel` (Block Kit + bot tokens) can
 * extend the notification, drop this class from `via()`, and add `'slack'`
 * instead.
 */
final class SlackWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof QueueAlertNotification) {
            return;
        }

        $url = method_exists($notifiable, 'routeNotificationForSlack')
            ? $notifiable->routeNotificationForSlack($notification)
            : '';

        if (! is_string($url) || $url === '') {
            return;
        }

        $payload = $notification->toSlack($notifiable);

        try {
            Http::asJson()
                ->timeout(5)
                ->post($url, $payload)
                ->throw();
        } catch (Throwable $throwable) {
            // Receiver rejected the Block-Kit-style payload (e.g. Mattermost
            // or a webhook proxy that wants `text` only). Retry with the
            // bare-minimum text so the operator at least sees something.
            try {
                Http::asJson()
                    ->timeout(5)
                    ->post($url, [
                        'text' => is_string($payload['text'] ?? null) ? $payload['text'] : $notification->issue->title,
                    ])
                    ->throw();
            } catch (Throwable $fallback) {
                Log::warning('queue-insights: slack webhook delivery failed', [
                    'rule' => $notification->issue->rule,
                    'severity' => $notification->issue->severity->value,
                    'primary_error' => $throwable->getMessage(),
                    'fallback_error' => $fallback->getMessage(),
                ]);
            }
        }
    }
}
