<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Display-derivation helpers for the dashboard view layer. Centralises
 * the per-render aggregations that the Livewire component (and the
 * workbench preview) feed into the view contract — total depth /
 * in-flight, at-risk vs healthy partition, deepest-N ordering, and the
 * Overview-card preview lists.
 *
 * Lifted out of `dashboard.blade.php`'s 47-line `@php` block so the
 * derivations are unit-testable and the view becomes a thin shell.
 */
final class QueueAggregates
{
    /**
     * Partition the queue list into at-risk vs healthy + sum the depth
     * and in-flight totals + return a depth-desc-sorted "deepest" copy.
     *
     * @param  list<array<string, mixed>>  $queues
     * @return array{
     *     total_depth: int,
     *     total_inflight: int,
     *     at_risk: list<array<string, mixed>>,
     *     healthy: list<array<string, mixed>>,
     *     deepest: list<array<string, mixed>>,
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

        $deepest = $queues;
        usort($deepest, function (array $a, array $b): int {
            $ad = is_numeric($a['depth'] ?? null) ? (int) $a['depth'] : 0;
            $bd = is_numeric($b['depth'] ?? null) ? (int) $b['depth'] : 0;

            return $bd <=> $ad;
        });

        return [
            'total_depth' => $totalDepth,
            'total_inflight' => $totalInFlight,
            'at_risk' => $atRisk,
            'healthy' => $healthy,
            'deepest' => $deepest,
        ];
    }

    /**
     * Build the Overview "Queues" card preview: at-risk first, padded by
     * the deepest queues until the cap is reached, deduplicating by
     * `(connection, queue)` key.
     *
     * @param  list<array<string, mixed>>  $atRisk
     * @param  list<array<string, mixed>>  $deepest
     * @return list<array<string, mixed>>
     */
    public static function queuePreview(array $atRisk, array $deepest, int $cap = 5): array
    {
        $preview = $atRisk;
        if (count($preview) < $cap) {
            foreach ($deepest as $q) {
                $dup = false;
                foreach ($preview as $a) {
                    if (($a['queue'] ?? null) === ($q['queue'] ?? null)
                        && ($a['connection'] ?? null) === ($q['connection'] ?? null)) {
                        $dup = true;
                        break;
                    }
                }

                if (! $dup) {
                    $preview[] = $q;
                }

                if (count($preview) >= $cap) {
                    break;
                }
            }
        }

        return array_slice($preview, 0, $cap);
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
