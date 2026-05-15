<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Console\Scheduling\Event;
use SanderMuller\QueueInsights\Scheduler\CommandLabel;
use SanderMuller\QueueInsights\Scheduler\TaskSummariser;
use Throwable;

/**
 * Resolve a human-readable label and the summarised task shape for a
 * scheduler-domain Issue.
 *
 * The `task_key` baked into scheduler alerts is a SHA256 — stable, but
 * opaque to operators reading mail/Slack. Pulling `command`,
 * `description`, `expression` off the `Event` lets the alert say
 * "Scheduled task missed: php artisan reports:export" instead of a
 * 64-char hash.
 *
 * Summarisation is wrapped in try/catch: `TaskSummariser` calls
 * `mutexName()` which can throw on partial mocks (test fixtures) or
 * on a not-yet-finalised Event. Failure falls back to the short
 * `task_key` prefix so a partial Event never blocks the alert.
 *
 * @internal
 */
final class ScheduledTaskLabel
{
    /**
     * @return array{
     *   label: string,
     *   summary: ?array{
     *     description: ?string,
     *     command: string,
     *     expression: string,
     *     timezone: ?string,
     *     runInBackground: bool,
     *     onOneServer: bool,
     *     evenInMaintenanceMode: bool,
     *     withoutOverlapping: bool,
     *     mutexName: string,
     *     type: 'command'|'closure'|'exec',
     *   },
     * }
     */
    public static function for(?Event $task, string $taskKey): array
    {
        $summary = self::summarise($task);

        return [
            'label' => self::label($summary, $taskKey),
            'summary' => $summary,
        ];
    }

    /**
     * @return ?array{
     *   description: ?string,
     *   command: string,
     *   expression: string,
     *   timezone: ?string,
     *   runInBackground: bool,
     *   onOneServer: bool,
     *   evenInMaintenanceMode: bool,
     *   withoutOverlapping: bool,
     *   mutexName: string,
     *   type: 'command'|'closure'|'exec',
     * }
     */
    private static function summarise(?Event $task): ?array
    {
        if (! $task instanceof Event) {
            return null;
        }

        try {
            return TaskSummariser::summarise($task);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  ?array{description: ?string, command: string, type: 'command'|'closure'|'exec'}  $summary
     */
    private static function label(?array $summary, string $taskKey): string
    {
        $description = AlertText::sanitise($summary['description'] ?? null);
        if ($description !== '') {
            return $description;
        }

        $command = AlertText::sanitise($summary['command'] ?? null);
        if ($command !== '') {
            return CommandLabel::short($command);
        }

        return substr($taskKey, 0, 12);
    }
}
