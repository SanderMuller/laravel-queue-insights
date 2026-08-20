<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
use SanderMuller\QueueInsights\Scheduler\SnapshotRebuildGate;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Rewrites the scheduled-task roster when a scheduler-relevant console
 * command starts.
 *
 * The rebuild used to fire from `app->booted`, i.e. on every artisan
 * invocation — a host's own command, `migrate`, a CI one-off — each
 * paying a Redis round-trip and logging a warning when Redis is
 * unreachable. `CommandStarting` fires after the whole provider stack
 * has booted (so `Schedule::events()` is fully populated, the same
 * guarantee `booted` gave) and carries the command name, so the write
 * can be scoped to the commands that actually care about the roster.
 */
final class RebuildScheduleSnapshot
{
    public function handle(CommandStarting $event): void
    {
        if (! Config::bool('scheduler.snapshot_rebuild', true)) {
            return;
        }

        if (! SnapshotRebuildGate::matches($event->command)) {
            return;
        }

        $app = Container::getInstance();
        if (! $app->bound(Schedule::class)) {
            return;
        }

        $app->make(ScheduleSnapshotter::class)->rebuild();
    }
}
