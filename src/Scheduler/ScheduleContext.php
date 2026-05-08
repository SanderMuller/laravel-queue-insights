<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Static stack of (taskKey, runId) frames pushed by the scheduled-task
 * Starting listener and popped by Finished/Failed. The job-side
 * `RecordJobQueued` listener reads `current()` and stamps the active
 * frame onto the queued job's metadata.
 *
 * Stack semantics (not single-frame): `Schedule::call(fn () => Bus::chain([...]))`
 * dispatches a child chain whose first link's `JobQueued` fires inside
 * the parent task's closure. We don't currently nest scheduled tasks
 * inside one another, but the stack is cheap and lets us add nesting
 * later without a rewrite.
 *
 * Process boundary: `runInBackground()` tasks fire in a separate child
 * process. `ScheduleContext` is per-process state — background tasks
 * lose attribution. Documented in 04-advanced.md §4.
 *
 * Octane / FrankenPHP guard: jobs queued from inside an HTTP request
 * must not pick up a stale schedule frame from a co-tenant on the same
 * worker. `push()` checks `runningInConsole()`; the schedule path runs
 * exclusively under `schedule:run` so the gate is sound.
 */
final class ScheduleContext
{
    /**
     * @var list<array{task_key: string, run_id: string}>
     */
    private static array $stack = [];

    public static function push(string $taskKey, string $runId): void
    {
        if (! self::isCli()) {
            return;
        }

        self::$stack[] = ['task_key' => $taskKey, 'run_id' => $runId];
    }

    /**
     * Top of the stack — what `RecordJobQueued` should attribute new jobs to.
     *
     * @return ?array{task_key: string, run_id: string}
     */
    public static function current(): ?array
    {
        $count = count(self::$stack);

        return $count === 0 ? null : self::$stack[$count - 1];
    }

    public static function pop(string $taskKey): void
    {
        for ($i = count(self::$stack) - 1; $i >= 0; --$i) {
            if (self::$stack[$i]['task_key'] === $taskKey) {
                array_splice(self::$stack, $i, 1);

                return;
            }
        }
    }

    public static function flush(): void
    {
        self::$stack = [];
    }

    private static function isCli(): bool
    {
        try {
            $app = Container::getInstance();
            if ($app instanceof Application) {
                return $app->runningInConsole();
            }
        } catch (Throwable) {
            // fall through
        }

        return PHP_SAPI === 'cli';
    }
}
