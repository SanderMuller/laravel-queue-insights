<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Fired by the package after a scheduled task records a failed run.
 * Host apps subscribe to forward to PagerDuty / Sentry / Slack.
 *
 * Distinct from `Illuminate\Console\Events\ScheduledTaskFailed` — Laravel's
 * event fires synchronously from `schedule:run` regardless of cooldown,
 * while ours is gated by `alerts.cooldown_seconds`.
 */
final readonly class ScheduledTaskFailed
{
    /**
     * @param  array<string, mixed>|null  $failureContext  sanitized failure-context
     *         snapshot (`['app_context' => [...], 'environment' => [...]]`), or null
     *         when `failure_context` is disabled. Trailing + defaulted so existing
     *         host constructions of this shipped event keep working.
     */
    public function __construct(
        public string $taskKey,
        public string $runId,
        public Event $task,
        public ?Throwable $exception,
        public ?array $failureContext = null,
    ) {}
}
