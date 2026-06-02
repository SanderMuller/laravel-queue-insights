<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\SentryAvailability;

/**
 * Internal value object for the alerts pipeline. Detectors construct
 * `Issue` and `IssueDispatcher` translates each one into a host-facing
 * event (where `severity` is exposed as a `string` for backwards-compat).
 *
 * @internal
 */
final readonly class Issue
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $rule,
        public AlertSeverity $severity,
        public string $connection,
        public string $queue,
        public ?string $jobClass,
        public string $title,
        public string $description,
        public array $context,
        public int $detectedAt,
        public ?string $taskKey = null,
    ) {}

    public function cooldownKeySuffix(): string
    {
        if ($this->taskKey !== null) {
            return "alert:cooldown:{$this->rule}:task:{$this->taskKey}";
        }

        if ($this->jobClass !== null) {
            return "alert:cooldown:{$this->rule}:class:{$this->jobClass}";
        }

        return "alert:cooldown:{$this->rule}:{$this->connection}:{$this->queue}";
    }

    /**
     * Scheduler-domain rules carry a `taskKey`; queue-side rules don't.
     * Used by the notification path to pick the right config block.
     */
    public function isSchedulerScoped(): bool
    {
        return $this->taskKey !== null;
    }

    /**
     * Resolve the dotted config-path root that drives both `via()` and the
     * `routeNotificationFor*` lookups. Single source of truth so the channel
     * selector and destination lookup cannot diverge — operator who populates
     * only `webhook_url` without flipping any `enabled` flag must NOT route
     * alerts to that webhook.
     *
     * Queue-side issues read `alerts.channels`. Scheduler-side issues read
     * `scheduler.alerts.channels` only when at least one channel inside it is
     * explicitly enabled; otherwise fall back to queue-side so single-list
     * installs Just Work.
     */
    public function channelConfigRoot(): string
    {
        if (! $this->isSchedulerScoped()) {
            return 'alerts.channels';
        }

        // Sentry additionally requires a bound client: a scheduler-sentry-only
        // block with no initialised hub must NOT win the root, otherwise the
        // sentry channel is skipped at send time and the scheduler issue is
        // left with zero channels instead of falling back to queue-side.
        // (log/slack/mail's runtime bindings are effectively always present in
        // a real app, so only sentry needs the availability guard here.)
        if (Config::bool('scheduler.alerts.channels.log.enabled', false)
            || Config::bool('scheduler.alerts.channels.slack.enabled', false)
            || Config::bool('scheduler.alerts.channels.mail.enabled', false)
            || (Config::bool('scheduler.alerts.channels.sentry.enabled', false) && SentryAvailability::available())) {
            return 'scheduler.alerts.channels';
        }

        return 'alerts.channels';
    }
}
