<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;

/** @internal */
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
        $configured = [];
        $canonical = [];
        foreach ($this->svc->configuredQueues($scopeConnection) as $entry) {
            try {
                $canonicalQueue = CanonicalQueueKey::from($entry['queue']);
            } catch (InvalidArgumentException) {
                // Invalid entry — skip rather than crash the whole render.
                // Boot-time ConfigValidator catches these at boot; this guards
                // against runtime `config()->set()` reconfigs bypassing it.
                continue;
            }

            $configured[] = $entry;
            $canonical[] = $canonicalQueue;
        }

        if ($configured === []) {
            return [];
        }

        // Pipeline every per-queue snapshot read into a single round-trip.
        // QueueInsights::queueRowSnapshots batches depth/delayed/inflight/
        // snapshot:error/lastSnapshotAt/pendingTrackedCount so a 10-queue
        // dashboard pays 1 RTT instead of 60.
        $pairs = [];
        foreach ($configured as $i => $entry) {
            $pairs[] = ['connection' => $entry['connection'], 'queue' => $canonical[$i]];
        }
        $snapshots = $this->svc->queueRowSnapshots($pairs);
        $now = Date::now();

        $rows = [];
        foreach ($configured as $i => $entry) {
            $connection = $entry['connection'];
            $queue = $entry['queue'];
            $canonicalQueue = $canonical[$i];
            $snapshot = $snapshots[$i] ?? [
                'depth' => 0,
                'delayed' => null,
                'inflight' => null,
                'error' => null,
                'last_at' => null,
                'pending_tracked_count' => null,
            ];

            $depth = $snapshot['depth'];
            $delayed = $snapshot['delayed'];
            $lastAt = $snapshot['last_at'];
            $stale = ! $lastAt instanceof CarbonInterface || $lastAt->diffInSeconds($now) > 120;

            $driverRaw = config("queue.connections.{$connection}.driver", '—');

            $waitPercentiles = $this->svc->queueWaitPercentiles($connection, $canonicalQueue);

            $rows[] = $this->attachInspectorFields(
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'canonical' => $canonicalQueue,
                    'driver' => is_string($driverRaw) ? $driverRaw : '—',
                    'depth' => $depth,
                    'inflight' => $snapshot['inflight'],
                    'delayed' => $delayed,
                    'last_at' => $lastAt,
                    'stale' => $stale,
                    'error' => $snapshot['error'],
                    'wait_p50_ms' => $waitPercentiles['p50'],
                    'wait_p95_ms' => $waitPercentiles['p95'],
                ],
                $expandedQueueKey,
                $connection,
                $canonicalQueue,
                $depth,
                $delayed,
                $snapshot['pending_tracked_count'],
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
        ?int $trackedCount,
    ): array {
        if ($trackedCount === null) {
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

        $actual = $depth + ($delayed ?? 0);
        $gap = abs($trackedCount - $actual);

        return $row + [
            'inspector_key' => $key,
            'inspector_open' => $isOpen,
            'inspector_disabled' => false,
            'tracked_count' => $trackedCount,
            'pending_gap' => $gap,
            'pending_jobs' => $isOpen ? $this->svc->pendingJobs($connection, $canonical) : [],
            'delayed_jobs' => $isOpen ? $this->svc->delayedJobs($connection, $canonical) : [],
        ];
    }
}
