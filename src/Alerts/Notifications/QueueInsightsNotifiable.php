<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications;

use Illuminate\Notifications\Notifiable;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Default notifiable used by IssueDispatcher. Reads routing addresses from
 * config so the out-of-the-box experience is "set the env vars, you're
 * done". Host apps wanting custom routing — per-environment, per-rule,
 * Slack-channel-per-severity, etc. — should bind their own implementation
 * of this class to the container:
 *
 *     app()->bind(QueueInsightsNotifiable::class, MyCustomNotifiable::class);
 *
 * Or extend it and override `routeNotificationFor*()` methods. Adding
 * Discord / Teams / PagerDuty / Telegram = install the matching
 * `laravel-notification-channels/*` package, override
 * `QueueAlertNotification::via()` and add `routeNotificationForX()` here.
 *
 * Pattern mirrors spatie/laravel-backup's `Notifiable` and
 * laravel/horizon's `Horizon::routeXNotificationsTo()` helpers.
 */
class QueueInsightsNotifiable
{
    use Notifiable;

    /**
     * Stable identity for `Notification::fake()` assertions and Laravel's
     * channel deduplication. The class is effectively a singleton route map,
     * so a constant key is correct.
     */
    public function getKey(): string
    {
        return 'queue-insights';
    }

    /**
     * @return list<string>|string
     */
    public function routeNotificationForMail(): array|string
    {
        $to = Config::array('alerts.channels.mail.to');

        $addresses = [];
        foreach ($to as $address) {
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public function routeNotificationForSlack(): string
    {
        return Config::string('alerts.channels.slack.webhook_url', '');
    }
}
