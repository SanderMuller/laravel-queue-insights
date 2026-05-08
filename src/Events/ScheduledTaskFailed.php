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
    public function __construct(
        public string $taskKey,
        public string $runId,
        public Event $task,
        public ?Throwable $exception,
    ) {}
}
