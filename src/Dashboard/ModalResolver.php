<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\BatchReader;

/**
 * Resolves the open modal target from the dashboard component's selection
 * state. Two methods carry service-backed fallbacks so deep-linked
 * selections that fall outside the loaded windows still resolve:
 * `selectedPending` falls back to a per-uuid Redis lookup,
 * `selectedBatch` falls back to `BatchReader::detailRow()`. These
 * fallbacks are existing behaviour with explicit feature-test coverage.
 *
 * @internal
 */
final readonly class ModalResolver
{
    public function __construct(
        private QueueInsights $svc,
    ) {}

    /**
     * @param  list<array<string, string>>  $recentCompleted
     * @return array<string, string>|null
     */
    public function selectedPayload(?string $selectedId, array $recentCompleted): ?array
    {
        if ($selectedId === null) {
            return null;
        }

        foreach ($recentCompleted as $entry) {
            if (($entry['_id'] ?? null) === $selectedId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $recentFailed
     * @return array<array-key, mixed>|null
     */
    public function selectedFailed(?int $selectedId, array $recentFailed): ?array
    {
        if ($selectedId === null) {
            return null;
        }

        foreach ($recentFailed as $row) {
            if (is_numeric($row['id'] ?? null) && (int) $row['id'] === $selectedId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Look up the currently-open pending row by uuid. Searches the rows
     * already loaded for the section, then falls back to a per-uuid hash
     * lookup so a batched job sitting outside the top-50 aggregates (or
     * any uuid arrived at via a deep-linked URL) still mounts with real
     * data — not the misleading "no longer pending" empty state.
     *
     * Returns null only when the uuid genuinely isn't tracked anymore
     * (worker grabbed it mid-modal, TTL fired, or pending tracking was
     * disabled at queue time).
     *
     * @param  list<array<string, mixed>>  $allRows  inFlight + pending + delayed merged
     * @return array<string, mixed>|null
     */
    public function selectedPending(?string $selectedUuid, array $allRows): ?array
    {
        if ($selectedUuid === null) {
            return null;
        }

        foreach ($allRows as $row) {
            if (($row['uuid'] ?? null) === $selectedUuid) {
                return $row;
            }
        }

        return $this->svc->findPendingByUuid($selectedUuid);
    }

    /**
     * Resolve the open batch row. Searches the visible Batches section
     * first, then falls back to `BatchReader::detailRow()` so a batch
     * chip whose target sits outside the recent-batches window
     * (`batches.max_per_query`) still resolves — without the fallback
     * the modal would land on the misleading "Batch no longer tracked"
     * empty state even though `Bus::findBatch()` succeeds.
     *
     * Returns null only when the BatchRepository row genuinely aged out.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return array<string, mixed>|null
     */
    public function selectedBatch(string $expandedBatchId, array $batches): ?array
    {
        if ($expandedBatchId === '') {
            return null;
        }

        foreach ($batches as $row) {
            if (($row['id'] ?? null) === $expandedBatchId) {
                return $row;
            }
        }

        return BatchReader::detailRow($expandedBatchId);
    }
}
