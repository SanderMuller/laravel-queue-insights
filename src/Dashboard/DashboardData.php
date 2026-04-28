<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Support\Facades\Gate;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\BatchReader;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\QueueAggregates;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Support\WaitTimeMetrics;
use Throwable;

/**
 * Builds the full view-data array the dashboard component passes to its
 * Blade template. Composes every other builder + resolver in this
 * namespace so the Livewire component's `render()` method stays a
 * one-liner.
 *
 * @internal
 */
final readonly class DashboardData
{
    /**
     * Per-page cap for the Completed and Failed lists. Fixed at 25 so the
     * tab content stays above-fold-friendly. Page is clamped to the
     * available range at render time.
     */
    public const int PER_PAGE = 25;

    /**
     * The exact set of keys `build()` returns. Used by
     * `PreviewDashboardSmokeTest` to assert the workbench preview's
     * seedData() matches the production view contract — drift caught at
     * test time rather than in a host's Blade template.
     */
    public const array EXPECTED_KEYS = [
        'queues',
        'totalDepth',
        'totalInFlight',
        'atRisk',
        'healthy',
        'queuePreview',
        'pendingPreview',
        'fmtMs',
        'classes',
        'pendingRows',
        'delayedRows',
        'inFlightRows',
        'pendingEnabled',
        'filterConnectionOptions',
        'filterQueueOptions',
        'filterClassOptions',
        'captureMode',
        'completedRows',
        'completedTotal',
        'completedPage',
        'completedTotalPages',
        'completedPerPage',
        'completedFiltersActive',
        'failedRows',
        'failedTotal',
        'failedPage',
        'failedTotalPages',
        'failedPerPage',
        'selectedClass',
        'selectedPayload',
        'selectedFailed',
        'payloadTab',
        'throughput',
        'stats',
        'pendingGapWarnThreshold',
        'failedFiltersActive',
        'canRetry',
        'bulkRetryCount',
        'batches',
        'batchesEnabled',
        'expandedBatchId',
        'selectedBatch',
        'selectedPending',
        'selectedPendingUuid',
        'hasOpenModal',
    ];

    public function __construct(
        private QueueInsights $svc,
        private ModalResolver $modals,
        private ClassRowsBuilder $classRowsBuilder,
        private FilterOptionsBuilder $filterOptionsBuilder,
        private HeadlineStatsBuilder $headlineStatsBuilder,
        private QueueRowsBuilder $queueRowsBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(QueueInsightsDashboard $component): array
    {
        $captureMode = Config::string('capture.payloads', 'off');

        $queues = $this->queueRowsBuilder->build($component->expandedQueueKey);
        $classes = $this->classRowsBuilder->build();

        $failedFilters = $component->buildFailedFilters();

        $recentCompleted = $this->svc->recentCompleted(50, $component->selectedClass);
        $recentFailed = $this->svc->recentFailed(50, $failedFilters);
        $throughput = $this->svc->hourlyThroughput();

        $selectedPayload = $this->modals->selectedPayload($component->selectedPayloadId, $recentCompleted);
        $selectedFailed = $this->modals->selectedFailed($component->selectedFailedId, $recentFailed);

        // Decorate the selected rows with the per-job wait sample. Modals
        // render `Wait: —` when this is null (legacy job pre-dating the
        // JobQueued listener, or a driver that omits payload.uuid).
        if ($selectedPayload !== null) {
            $payloadUuid = $selectedPayload['uuid'] ?? null;
            $selectedPayload['wait_ms'] = is_string($payloadUuid)
                ? (string) ($this->svc->jobWaitMs($payloadUuid) ?? '')
                : '';

            // Reverse-lookup the batch id for THIS uuid only (one MGET) so
            // the details-modal's batch chip can render. The full
            // recentCompleted enrichment runs later for the table; doing
            // it again here for one row is the cheapest way to keep the
            // modal shape stable without depending on table-render order.
            if (is_string($payloadUuid) && $payloadUuid !== '') {
                $payloadBatchIds = BatchReader::batchIdsForUuids([$payloadUuid]);
                $selectedPayload['batch_id'] = $payloadBatchIds[$payloadUuid] ?? null;
            }
        }

        if ($selectedFailed !== null) {
            $failedUuid = $selectedFailed['uuid'] ?? null;
            $selectedFailed['wait_ms'] = is_string($failedUuid)
                ? $this->svc->jobWaitMs($failedUuid)
                : null;
        }

        // Bulk-retry UI eligibility (server-side enforcement still applies in
        // QueueInsightsDashboard::retryFailedBulk()). Only check when:
        //   - filters are non-empty (footgun guard)
        //   - the host has defined the retryFailedJobs gate at all
        //     (otherwise the button has nowhere to land).
        $canRetry = Gate::has('retryFailedJobs') && Gate::allows('retryFailedJobs');
        $bulkRetryCount = null;
        if ($canRetry && ! $failedFilters->isEmpty()) {
            try {
                $bulkRetryCount = count($component->collectFilteredFailedUuids($failedFilters));
            } catch (Throwable) {
                $bulkRetryCount = null;
            }
        }

        $filterOptions = $this->filterOptionsBuilder->build($classes);

        $batches = BatchReader::sectionRows($this->svc, $component->expandedBatchId);

        $pendingEnabled = Config::bool('pending.enabled', true);
        $pendingRows = $pendingEnabled ? $this->svc->allPendingJobs(50) : [];
        $delayedRows = $pendingEnabled ? $this->svc->allDelayedJobs(50) : [];
        $inFlightRows = $pendingEnabled ? $this->svc->allInFlightJobs(50) : [];

        $selectedPending = $this->modals->selectedPending(
            $component->selectedPendingUuid,
            array_merge($inFlightRows, $pendingRows, $delayedRows),
        );

        $selectedBatch = $this->modals->selectedBatch($component->expandedBatchId, $batches);

        // Drive `inert` from the same booleans that actually mount the
        // modals — codex review #1. Routing it off raw selection ids
        // froze the dashboard when an id was set but the modal never
        // mounted (config flip, aged-out row, ?batch= URL with batches
        // disabled).
        $hasOpenModal = $selectedPayload !== null
            || $selectedFailed !== null
            || ($pendingEnabled && $component->selectedPendingUuid !== null)
            || (Config::bool('batches.enabled', true) && $component->expandedBatchId !== '');

        // Server-side pagination — slice the post-filter list to the active
        // page so the tab pane never renders more than PER_PAGE rows. Page
        // is clamped to the actual range so a bookmarked deep page on a list
        // that's since shrunk gracefully lands on the last available page.
        $completedAll = $this->buildCompletedFilter($component)->apply(RowEnricher::completed($recentCompleted));
        $completedTotal = count($completedAll);
        $completedTotalPages = max(1, (int) ceil($completedTotal / self::PER_PAGE));
        $completedPage = min(max(1, $component->completedPage), $completedTotalPages);
        $completedRowsPaged = array_slice($completedAll, ($completedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        $failedAll = RowEnricher::failed($recentFailed);
        $failedTotal = count($failedAll);
        $failedTotalPages = max(1, (int) ceil($failedTotal / self::PER_PAGE));
        $failedPage = min(max(1, $component->failedPage), $failedTotalPages);
        $failedRowsPaged = array_slice($failedAll, ($failedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        $aggregates = QueueAggregates::aggregate($queues);

        return [
            'queues' => $queues,
            'totalDepth' => $aggregates['total_depth'],
            'totalInFlight' => $aggregates['total_inflight'],
            'atRisk' => $aggregates['at_risk'],
            'healthy' => $aggregates['healthy'],
            'queuePreview' => QueueAggregates::queuePreview($aggregates['at_risk'], $aggregates['deepest']),
            'pendingPreview' => QueueAggregates::pendingPreview($inFlightRows, $pendingRows, $delayedRows),
            'fmtMs' => WaitTimeMetrics::format(...),
            'classes' => $classes,
            'pendingRows' => $pendingRows,
            'delayedRows' => $delayedRows,
            'inFlightRows' => $inFlightRows,
            'pendingEnabled' => $pendingEnabled,
            'filterConnectionOptions' => $filterOptions['connections'],
            'filterQueueOptions' => $filterOptions['queues'],
            'filterClassOptions' => $filterOptions['classes'],
            'captureMode' => $captureMode,
            'completedRows' => $completedRowsPaged,
            'completedTotal' => $completedTotal,
            'completedPage' => $completedPage,
            'completedTotalPages' => $completedTotalPages,
            'completedPerPage' => self::PER_PAGE,
            'completedFiltersActive' => $this->completedFiltersActive($component),
            'failedRows' => $failedRowsPaged,
            'failedTotal' => $failedTotal,
            'failedPage' => $failedPage,
            'failedTotalPages' => $failedTotalPages,
            'failedPerPage' => self::PER_PAGE,
            'selectedClass' => $component->selectedClass,
            'selectedPayload' => $selectedPayload,
            'selectedFailed' => $selectedFailed,
            'payloadTab' => $component->payloadTab,
            'throughput' => $throughput,
            'stats' => $this->headlineStatsBuilder->build($throughput, $queues, $classes),
            'pendingGapWarnThreshold' => Config::int('pending.gap_warn_threshold', 5),
            'failedFiltersActive' => ! $failedFilters->isEmpty(),
            'canRetry' => $canRetry,
            'bulkRetryCount' => $bulkRetryCount,
            'batches' => $batches,
            'batchesEnabled' => Config::bool('batches.enabled', true),
            'expandedBatchId' => $component->expandedBatchId,
            'selectedBatch' => $selectedBatch,
            'selectedPending' => $selectedPending,
            'selectedPendingUuid' => $component->selectedPendingUuid,
            'hasOpenModal' => $hasOpenModal,
        ];
    }

    private function buildCompletedFilter(QueueInsightsDashboard $component): CompletedRowFilter
    {
        return new CompletedRowFilter(
            connection: $component->completedFilterConnection,
            queue: $component->completedFilterQueue,
            from: $component->completedFilterFrom,
            to: $component->completedFilterTo,
        );
    }

    private function completedFiltersActive(QueueInsightsDashboard $component): bool
    {
        return $component->selectedClass !== null
            || $component->completedFilterConnection !== ''
            || $component->completedFilterQueue !== ''
            || $component->completedFilterFrom !== ''
            || $component->completedFilterTo !== '';
    }
}
