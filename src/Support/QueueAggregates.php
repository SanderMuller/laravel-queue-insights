<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/** @internal */
final class QueueAggregates
{
    /**
     * Partition the queue list into at-risk vs healthy + sum the depth
     * and in-flight totals.
     *
     * @param  list<array<string, mixed>>  $queues
     * @return array{
     *     total_depth: int,
     *     total_inflight: int,
     *     at_risk: list<array<string, mixed>>,
     *     healthy: list<array<string, mixed>>,
     * }
     */
    public static function aggregate(array $queues): array
    {
        $totalDepth = array_sum(array_map(
            fn (array $q): int => is_numeric($q['depth'] ?? null) ? (int) $q['depth'] : 0,
            $queues,
        ));
        $totalInFlight = array_sum(array_map(
            fn (array $q): int => is_numeric($q['inflight'] ?? null) ? (int) $q['inflight'] : 0,
            $queues,
        ));

        $atRisk = array_values(array_filter(
            $queues,
            fn (array $q): bool => (bool) ($q['error'] ?? false) || (bool) ($q['stale'] ?? false),
        ));
        $healthy = array_values(array_filter(
            $queues,
            fn (array $q): bool => ! (bool) ($q['error'] ?? false) && ! (bool) ($q['stale'] ?? false),
        ));

        return [
            'total_depth' => $totalDepth,
            'total_inflight' => $totalInFlight,
            'at_risk' => $atRisk,
            'healthy' => $healthy,
        ];
    }

    /**
     * Build the Overview "Pending" card preview: in-flight first (tagged
     * with `_isInFlight` so the dot pulses), then pending-now, then
     * delayed. Capped at $cap.
     *
     * @param  list<array<string, mixed>>  $inFlight
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $delayed
     * @return list<array<string, mixed>>
     */
    public static function pendingPreview(array $inFlight, array $pending, array $delayed, int $cap = 5): array
    {
        $preview = [];
        foreach ($inFlight as $r) {
            $preview[] = $r + ['_isInFlight' => true];
        }

        foreach ($pending as $r) {
            $preview[] = $r;
        }

        foreach ($delayed as $r) {
            $preview[] = $r;
        }

        return array_slice($preview, 0, $cap);
    }
}
