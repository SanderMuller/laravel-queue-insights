<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Dashboard banner helper — true when Horizon's service provider IS loaded
 * (so Horizon is the intended runtime here) AND supervisors are configured
 * for this env AND no master is heartbeating. The "you dispatched jobs but
 * forgot to start Horizon" state.
 *
 * Why the provider-loaded check comes first: on Vapor (provider deliberately
 * excluded, SQS is the runtime) Horizon is configured but never starts —
 * that is NOT a problem, it's the intended setup. The banner fires only
 * when the operator's app is meant to run Horizon.
 *
 * `MasterSupervisorRepository->all()` already returns only masters seen
 * within Horizon's own 14-second window (`RedisMasterSupervisorRepository::names()`
 * is `zrevrangebyscore('masters', '+inf', now()->subSeconds(14))`), so a
 * brief Horizon restart blips the banner for ~14s and then it clears. No
 * additional debounce — a banner can flash harmlessly. See spec Resolved
 * #4–#6.
 *
 * Stateless. Safe under Octane.
 */
final class HorizonNotRunning
{
    public function isNotRunning(): bool
    {
        if (! HorizonQueueDiscovery::isActive()) {
            return false;
        }

        if (HorizonQueueDiscovery::discover() === []) {
            return false;
        }

        try {
            // Resolve the contract only after `isActive()` passes — Horizon
            // binds it in `HorizonServiceProvider::registerServices()`, which
            // ran iff the provider is loaded. Empty `all()` = no master
            // heartbeating in Horizon's 14s window.
            return resolve(MasterSupervisorRepository::class)->all() === [];
        } catch (Throwable) {
            // If Horizon's repo is unreachable (Redis down, misconfigured
            // horizon.use connection), don't banner — same conservative
            // stance SnapshotWatchdog takes: "don't know" → no false alarm.
            return false;
        }
    }
}
