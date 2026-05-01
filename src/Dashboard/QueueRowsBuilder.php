<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Builds the per-queue row set for the Queues pane. One row per
 * configured snapshot, decorated with live depth/in-flight/delayed
 * counts, last-snapshot timestamp, staleness flag, wait-time
 * percentiles, and pending-inspector fields.
 *
 * @internal
 */
final readonly class QueueRowsBuilder
{
    public function __construct(
        private QueueInsights $svc,
    ) {}

    /**
     * @param  string  $expandedQueueKey  the "{connection}:{canonical-queue}" of
     *                                    the expanded inspector, '' if none
     * @param  ?string  $scopeConnection  when non-null, restricts iteration to
     *                                    snapshots whose `connection` matches
     * @return list<array<string, mixed>>
     */
    public function build(string $expandedQueueKey, ?string $scopeConnection = null): array
    {
        $rows = [];

        foreach ($this->svc->configuredQueues($scopeConnection) as $entry) {
            $connection = $entry['connection'];
            $queue = $entry['queue'];

            try {
                $canonical = CanonicalQueueKey::from($queue);
            } catch (InvalidArgumentException) {
                // Invalid entry — skip rather than crash the whole render.
                // Boot-time ConfigValidator catches these at boot; this guards
                // against runtime `config()->set()` reconfigs bypassing it.
                continue;
            }

            $lastAt = $this->svc->lastSnapshotAt($connection, $canonical);
            $stale = ! $lastAt instanceof CarbonInterface || $lastAt->diffInSeconds(Date::now()) > 120;

            $driverRaw = config("queue.connections.{$connection}.driver", '—');

            $waitPercentiles = $this->svc->queueWaitPercentiles($connection, $canonical);

            $depth = $this->svc->liveDepth($connection, $canonical);
            $delayed = $this->svc->liveDelayed($connection, $canonical);

            $rows[] = $this->attachInspectorFields(
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'canonical' => $canonical,
                    'driver' => is_string($driverRaw) ? $driverRaw : '—',
                    'depth' => $depth,
                    'inflight' => $this->svc->liveInFlight($connection, $canonical),
                    'delayed' => $delayed,
                    'last_at' => $lastAt,
                    'stale' => $stale,
                    'error' => $this->svc->snapshotError($connection, $canonical),
                    'wait_p50_ms' => $waitPercentiles['p50'],
                    'wait_p95_ms' => $waitPercentiles['p95'],
                ],
                $expandedQueueKey,
                $connection,
                $canonical,
                $depth,
                $delayed,
            );
        }

        return $rows;
    }

    /**
     * Attach pending-inspector fields to a queue row. Always includes counts
     * + drift gap (cheap — one ZCARD per queue). Includes the actual pending
     * and delayed job lists ONLY when this row's inspector is expanded —
     * otherwise we'd run 2 ZRANGEBYSCOREs + 50× HGETALLs per visible queue
     * on every 10s poll.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attachInspectorFields(
        array $row,
        string $expandedQueueKey,
        string $connection,
        string $canonical,
        int $depth,
        ?int $delayed,
    ): array {
        if (! Config::bool('pending.enabled', true)) {
            return $row + [
                'inspector_key' => "{$connection}:{$canonical}",
                'inspector_open' => false,
                'inspector_disabled' => true,
                'tracked_count' => 0,
                'pending_gap' => 0,
                'pending_jobs' => [],
                'delayed_jobs' => [],
            ];
        }

        $key = "{$connection}:{$canonical}";
        $isOpen = $expandedQueueKey === $key;

        $tracked = $this->svc->pendingTrackedCount($connection, $canonical);
        $actual = $depth + ($delayed ?? 0);
        $gap = abs($tracked - $actual);

        return $row + [
            'inspector_key' => $key,
            'inspector_open' => $isOpen,
            'inspector_disabled' => false,
            'tracked_count' => $tracked,
            'pending_gap' => $gap,
            'pending_jobs' => $isOpen ? $this->svc->pendingJobs($connection, $canonical) : [],
            'delayed_jobs' => $isOpen ? $this->svc->delayedJobs($connection, $canonical) : [],
        ];
    }
}
