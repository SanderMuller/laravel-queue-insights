<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

use Throwable;

/**
 * Fired by the package when a job records its FINAL failure (retries
 * exhausted) and the `job_failed` alert rule is enabled. Host apps subscribe
 * to forward to PagerDuty / Sentry / Slack, or to dispatch their own queued
 * notification when `alerts.rules.job_failed.notify` is `false`.
 *
 * Distinct from `Illuminate\Queue\Events\JobFailed` — Laravel's event fires
 * synchronously on every final failure regardless of cooldown, while ours is
 * gated by `alerts.cooldown_seconds` (one per job class per window) and is
 * silencing-filtered (`queue-insights.silenced`).
 */
final readonly class JobFailedAlert
{
    public function __construct(
        public string $jobClass,
        public string $connection,
        public string $queue,
        public ?string $uuid,
        public ?Throwable $exception,
        public string $severity,
    ) {}
}
