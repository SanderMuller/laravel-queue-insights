<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Exceptions\ListenerFailedException;
use Throwable;

/**
 * Surfaces exceptions the job-lifecycle listeners swallow. Each listener
 * still logs a warning on every failure; this additionally hands one
 * `ListenerFailedException` per listener per `report_interval_seconds` to
 * the host's exception handler, so a missed tracking write (e.g. a pending
 * entry never moved to in-flight) leaves a trace in the error tracker
 * without flooding it during a Redis outage.
 *
 * The throttle is per worker process (container singleton), so N workers
 * report at most N events per interval per listener.
 *
 * @internal
 */
final class ListenerFailureReporter
{
    /** @var array<string, int> Last report time per listener. */
    private array $lastReportedAt = [];

    public function report(string $listener, Throwable $throwable): void
    {
        try {
            if (! Config::bool('listener_failures.report', true)) {
                return;
            }

            $now = Date::now()->getTimestamp();
            $interval = Config::int('listener_failures.report_interval_seconds', 300);
            $last = $this->lastReportedAt[$listener] ?? null;

            if ($last !== null && $now - $last < $interval) {
                return;
            }

            $this->lastReportedAt[$listener] = $now;

            report(new ListenerFailedException($listener, $throwable));
        } catch (Throwable) {
            // Reporting must never break the job the listener runs beside.
        }
    }
}
