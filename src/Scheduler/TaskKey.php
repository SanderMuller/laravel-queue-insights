<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

/**
 * Stable per-task key.
 *
 * For commands and exec tasks, Laravel's `mutexName()` is fully
 * disambiguated (`framework/schedule-{sha1(expression+command)}`).
 *
 * For `CallbackEvent` closures **without** `->name(...)`, Laravel's
 * `mutexName()` reduces to `'framework/schedule-' . sha1('')` — every
 * unnamed closure collapses to one shared key. We disambiguate inside
 * the package via `ReflectionFunction` on the closure (file:line). This
 * does NOT fix Laravel's framework-level collision (`withoutOverlapping`
 * still treats unnamed closures as the same task) — the workaround is
 * to call `->name(...)`.
 *
 * Stable across deploys as long as the closure stays at the same
 * file:line. Refactor that moves it = new task in the dashboard while
 * the old one fades via TTL.
 */
final class TaskKey
{
    /**
     * @return non-empty-string
     */
    public static function for(Event $task): string
    {
        if ($task instanceof CallbackEvent && self::isUnnamed($task)) {
            $reflectionName = self::closureReflectionName($task);
            if ($reflectionName !== null) {
                return hash('sha256', $reflectionName);
            }
        }

        return hash('sha256', $task->mutexName());
    }

    /**
     * Best-effort human-readable label for an unnamed closure
     * (`closure@routes/console.php:42`). Returns null when reflection
     * fails or the underlying callback isn't a closure.
     */
    public static function reflectionLabel(Event $task): ?string
    {
        if (! $task instanceof CallbackEvent) {
            return null;
        }

        return self::closureReflectionName($task);
    }

    private static function isUnnamed(CallbackEvent $task): bool
    {
        return ! is_string($task->description) || $task->description === '';
    }

    private static function closureReflectionName(CallbackEvent $task): ?string
    {
        try {
            $callback = self::readPrivate($task, 'callback');
        } catch (Throwable) {
            return null;
        }

        if (! $callback instanceof Closure) {
            return null;
        }

        try {
            $reflection = new ReflectionFunction($callback);
        } catch (Throwable) {
            return null;
        }

        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();
        if (! is_string($file) || $file === '' || ! is_int($line)) {
            return null;
        }

        $base = function_exists('base_path') ? base_path() . DIRECTORY_SEPARATOR : '';
        $rel = $base !== '' && str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;

        return "closure@{$rel}:{$line}";
    }

    private static function readPrivate(CallbackEvent $task, string $property): mixed
    {
        $reflection = new ReflectionProperty($task, $property);

        return $reflection->getValue($task);
    }
}
