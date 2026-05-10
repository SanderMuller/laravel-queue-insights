<?php declare(strict_types=1);

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
 *
 * Public method signatures stay parameter-less to preserve host-override
 * BC. Laravel's Notifiable trait passes the active notification via
 * `$this->{$method}($notification)` regardless of the declared signature;
 * we read it through `func_get_args()` so the scheduler-domain detection
 * works without forcing every host override to grow a parameter.
 */
class QueueInsightsNotifiable
{
    use Notifiable;

    public function getKey(): string
    {
        return 'queue-insights';
    }

    /**
     * @return list<string>|string
     */
    public function routeNotificationForMail(): array|string
    {
        $root = $this->resolveRoot(func_get_args());

        $addresses = [];
        foreach (Config::array("{$root}.mail.to") as $address) {
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public function routeNotificationForSlack(): string
    {
        $root = $this->resolveRoot(func_get_args());

        return Config::string("{$root}.slack.webhook_url", '');
    }

    /**
     * Delegate to `Issue::channelConfigRoot()` so the route lookup shares
     * one decision with `QueueAlertNotification::via()` — operator who
     * populates a webhook_url without flipping any `enabled` flag does NOT
     * silently send alerts to that webhook.
     *
     * Falls back to `alerts.channels` when the call wasn't initiated through
     * Laravel's Notifiable trait (e.g. tests calling the route method with
     * no args).
     *
     * @param  array<int, mixed>  $args
     */
    private function resolveRoot(array $args): string
    {
        $first = $args[0] ?? null;
        if (! $first instanceof QueueAlertNotification) {
            return 'alerts.channels';
        }

        return $first->issue->channelConfigRoot();
    }
}
