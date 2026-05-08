<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Console\Scheduling\Event;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Reads the tail of `$task->output` when capture mode is `full`.
 * Modes `off` / `metadata` return null — exit code is captured by the
 * listener regardless.
 *
 * Tails (not heads) so failure context lands in the captured slice.
 *
 * Phase 1 deviation: the schedule-output tail is captured raw with a
 * byte-cap; the host-bound `PayloadSanitizer` interface keys off
 * `JobProcessed` events and can't accept a string blob. Phase 4 of
 * the cron-monitoring spec wires a dedicated `OutputSanitizer`
 * contract — until then, hosts with sensitive scheduled-task output
 * SHOULD set `scheduler.capture.output = metadata` (the default).
 */
final class OutputCapturer
{
    public function capture(Event $task): ?string
    {
        $mode = Config::enum('scheduler.capture.output', CaptureMode::class, CaptureMode::Metadata);
        if ($mode !== CaptureMode::Full) {
            return null;
        }

        $path = $task->output;
        if (! is_string($path) || $path === '' || $path === '/dev/null' || $path === 'NUL') {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $cap = max(1, Config::int('scheduler.capture.max_output_bytes', 8192));
        $size = filesize($path);
        if ($size === false) {
            return null;
        }

        $offset = max(0, $size - $cap);
        $contents = file_get_contents($path, false, null, $offset, $cap);
        if ($contents === false) {
            return null;
        }

        return $offset > 0
            ? "[…truncated, showing last {$cap}B…]\n" . $contents
            : $contents;
    }
}
