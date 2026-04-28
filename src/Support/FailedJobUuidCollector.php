<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\DB;
use SanderMuller\QueueInsights\QueueInsights;

/**
 * Plucks uuids matching a failed-jobs filter set, capped at 101 rows so
 * the count check can distinguish "exactly 100" from "more than 100".
 *
 * Lives outside the Livewire dashboard component on purpose: a public
 * method on a Livewire component is part of the client-callable action
 * surface (`$wire.foo()`) regardless of any `@internal` tag. Hosting
 * the query here keeps the read-only / retry boundary intact — only
 * `QueueInsightsDashboard::retryFailedBulk()` (gate-checked) and
 * `Dashboard\DashboardData::build()` (server-side render only) call
 * into this class.
 *
 * @internal
 */
final class FailedJobUuidCollector
{
    /**
     * @return list<string>
     */
    public static function collect(FailedJobFilters $filters): array
    {
        $query = QueueInsights::applyFailedJobFilters(
            DB::table('failed_jobs')->orderByDesc('id')->limit(101),
            $filters,
        );

        $rows = $query->pluck('uuid')->all();

        $out = [];
        foreach ($rows as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
