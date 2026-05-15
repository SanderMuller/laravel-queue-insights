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
        $description = self::sanitise($summary['description'] ?? null);
        if ($description !== '') {
            return $description;
        }

        $command = self::sanitise($summary['command'] ?? null);
        if ($command !== '') {
            return CommandLabel::short($command);
        }

        return substr($taskKey, 0, 12);
    }

    /**
     * Strip control characters, collapse whitespace runs, and trim — so a
     * hostile `description` like `"   "` or one containing `\n` cannot blank
     * the alert title or split a log line into multiple records.
     *
     * Caps length at 200 chars so a pathological description doesn't push
     * the mail subject / Slack title past channel-side limits.
     */
    public static function sanitise(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        // `\p{C}` matches all Unicode "other" (control) characters incl. CR/LF
        // and zero-width separators. `u` flag is mandatory for the class.
        $stripped = preg_replace('/\p{C}+/u', ' ', $value);
        if (! is_string($stripped)) {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $stripped);
        if (! is_string($collapsed)) {
            return '';
        }

        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > 200) {
            return mb_substr($trimmed, 0, 197) . '...';
        }

        return $trimmed;
    }
}
