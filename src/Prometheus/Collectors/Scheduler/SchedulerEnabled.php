<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler;

use SanderMuller\QueueInsights\Support\Config;

/**
 * Shared gate helper for scheduler-side collectors.
 *
 * Per spec §1.5: every scheduler family additionally gates on the
 * master `scheduler.enabled` flag — emitting zero-valued samples when
 * scheduler capture is OFF would read as "everything is fine, no
 * failures ever" instead of "the data plane isn't writing." The
 * collector returns no families in that case.
 *
 * @internal
 */
trait SchedulerEnabled
{
    private function schedulerEnabled(): bool
    {
        return Config::bool('scheduler.enabled', false);
    }
}
