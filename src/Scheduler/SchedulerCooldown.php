<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Alerts\Cooldown;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;

/**
 * Backwards-compat shim. Phase 7b moved scheduler alerting onto the
 * shared `Alerts\IssueDispatcher` + `Alerts\Cooldown` pipeline; this
 * class is no longer wired into the listener / reconciler constructors
 * but stays importable so any host that referenced it directly keeps
 * compiling. Removed in v2 once the migration tick has elapsed.
 *
 * @deprecated use Alerts\Cooldown via IssueDispatcher instead
 *
 * @internal
 */
final class SchedulerCooldown
{
    /**
     * Delegates to the queue-side cooldown so the on-disk key shape
     * matches the post-7b namespace (`alert:cooldown:scheduled_task_*:task:{taskKey}`).
     * `$rule` is the short literal `failed` / `hung` / `missed`.
     */
    public function acquire(string $rule, string $taskKey): bool
    {
        $issue = new Issue(
            rule: "scheduled_task_{$rule}",
            severity: AlertSeverity::Warning,
            connection: '',
            queue: '',
            jobClass: null,
            title: '',
            description: '',
            context: [],
            detectedAt: Date::now()->getTimestamp(),
            taskKey: $taskKey,
        );

        return resolve(Cooldown::class)->acquire($issue);
    }
}
