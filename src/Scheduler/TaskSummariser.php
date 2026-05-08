<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Pure projection of an `Event` into the JSON shape stored on the
 * `qi:sched:tasks` hash. Lives standalone so the snapshotter and the
 * `schedule:list` command share one source of truth.
 */
final class TaskSummariser
{
    /**
     * @return array{
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
    public static function summarise(Event $task): array
    {
        $description = is_string($task->description) && $task->description !== ''
            ? $task->description
            : TaskKey::reflectionLabel($task);

        $timezoneRaw = $task->timezone;
        $timezone = is_string($timezoneRaw) && $timezoneRaw !== ''
            ? $timezoneRaw
            : ($timezoneRaw instanceof DateTimeZone ? $timezoneRaw->getName() : null);

        return [
            'description' => $description,
            'command' => self::resolveCommand($task),
            'expression' => is_string($task->expression) && $task->expression !== '' ? $task->expression : '* * * * *',
            'timezone' => $timezone,
            'runInBackground' => (bool) $task->runInBackground,
            'onOneServer' => (bool) $task->onOneServer,
            'evenInMaintenanceMode' => (bool) $task->evenInMaintenanceMode,
            'withoutOverlapping' => (bool) $task->withoutOverlapping,
            'mutexName' => $task->mutexName(),
            'type' => self::resolveType($task),
        ];
    }

    private static function resolveCommand(Event $task): string
    {
        if ($task instanceof CallbackEvent) {
            return is_string($task->description) && $task->description !== ''
                ? $task->description
                : 'closure';
        }

        return is_string($task->command) ? $task->command : '';
    }

    /**
     * @return 'command'|'closure'|'exec'
     */
    private static function resolveType(Event $task): string
    {
        if ($task instanceof CallbackEvent) {
            return 'closure';
        }

        $command = is_string($task->command) ? $task->command : '';

        // Laravel rewrites artisan commands as `'php' 'artisan' …` via
        // `Event::compileCommand`. Anything else is a raw shell exec.
        return str_contains($command, 'artisan') ? 'command' : 'exec';
    }
}
