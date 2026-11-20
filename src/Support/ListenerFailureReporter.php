<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Cache;
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
 * The throttle has two layers. A per-process one (container singleton)
 * keeps the hot path free of I/O. A shared one, a `Cache::add` lock on
 * the app's cache store, limits reports across all workers to one per
 * listener per interval. If the cache write fails too (the cache often
 * lives on the same Redis that is down), the per-process throttle alone
 * decides.
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

            if (! $this->claimSharedSlot($listener, $interval)) {
                return;
            }

            report(new ListenerFailedException($listener, $throwable));
        } catch (Throwable) {
            // Reporting must never break the job the listener runs beside.
        }
    }

    private function claimSharedSlot(string $listener, int $interval): bool
    {
        try {
            $store = Config::string('listener_failures.cache_store');

            return Cache::store($store === '' ? null : $store)->add("queue-insights:listener-failure-reported:{$listener}", 1, max(1, $interval));
        } catch (Throwable) {
            return true;
        }
    }
}
