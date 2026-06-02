<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications\Channels;

use Illuminate\Notifications\Notification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Support\SentryAvailability;
use Sentry\Severity;
use Sentry\State\Scope;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Custom Notification channel that captures the alert into the host
 * application's existing Sentry hub. Unlike SlackWebhookChannel there is no
 * per-notifiable destination — the Sentry SDK is a process-global hub
 * (`SentrySdk::getCurrentHub()`), typically initialised by the host's
 * `sentry/sentry-laravel` provider from `SENTRY_LARAVEL_DSN`. This channel
 * therefore reads the Issue directly (modelled on LogChannel) and calls the
 * global SDK.
 *
 * The SDK is an optional dependency: `QueueAlertNotification::via()` only adds
 * this channel when `function_exists('Sentry\captureMessage')`. The guard here
 * is belt-and-suspenders for direct callers.
 */
final class SentryChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof QueueAlertNotification) {
            return;
        }

        if (! SentryAvailability::available()) {
            return;
        }

        $payload = $notification->toSentry($notifiable);
        $level = $payload['level'] === 'error' ? Severity::error() : Severity::warning();

        withScope(function (Scope $scope) use ($payload, $level): void {
            $scope->setLevel($level);
            $scope->setFingerprint($payload['fingerprint']);

            foreach ($payload['tags'] as $key => $value) {
                $scope->setTag($key, $value);
            }

            $scope->setContext('queue-insights', $payload['extra']);

            // Pass $level explicitly — `Scope` exposes no public `getLevel()`,
            // and captureMessage's level argument wins over the scope anyway.
            captureMessage($payload['message'], $level);
        });
    }
}
