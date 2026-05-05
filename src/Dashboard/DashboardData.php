<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Support\Facades\Gate;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Alerts\SnapshotWatchdog;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\BatchReader;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\FailedJobUuidCollector;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Support\QueueAggregates;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Support\SilencedJobs;
use SanderMuller\QueueInsights\Support\UuidResolver;
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
     * Underlying fetch ceiling for the Completed + Failed tabs. Pages
     * are sliced from this set, so this caps how deep the user can
     * paginate ("recent {RECENT_FETCH_LIMIT} jobs" — older history is
     * not paginated by design).
     */
    public const int RECENT_FETCH_LIMIT = self::PER_PAGE * 10;

    /**
     * The exact set of keys `build()` returns. Used by
     * `PreviewDashboardSmokeTest` to assert the workbench preview's
     * seedData() matches the production view contract — drift caught at
     * test time rather than in a host's Blade template.
     */
    public const array EXPECTED_KEYS = [
        'scopeConnection',
        'connectionNav',
        'activeIssues',
        'snapshotCommandDead',
        'queues',
        'totalDepth',
        'totalInFlight',
        'atRisk',
        'healthy',
        'queuePreview',
        'pendingPreview',
        'completedPreview',
        'failedPreview',
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
        'chainBackTop',
        'silencedClasses',
        'silencedFailedRows',
        'silencedCompletedRows',
    ];

    public function __construct(
        private QueueInsights $svc,
        private ModalResolver $modals,
        private ClassRowsBuilder $classRowsBuilder,
        private FilterOptionsBuilder $filterOptionsBuilder,
        private HeadlineStatsBuilder $headlineStatsBuilder,
        private QueueRowsBuilder $queueRowsBuilder,
        private ActiveIssuesProvider $activeIssues,
        private SnapshotWatchdog $watchdog,
        private ConnectionNavBuilder $connectionNav,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(QueueInsightsDashboard $component): array
    {
        // Pass the enum directly — the modal partial accepts both the
        // enum (production path) and a raw string (legacy host overrides)
        // and normalises before its comparisons.
        $captureMode = Config::enum('capture.payloads', CaptureMode::class, CaptureMode::Off);

        $scope = $component->scopeConnection;

        $queues = $this->queueRowsBuilder->build($component->expandedQueueKey, $scope);
        $classes = $this->classRowsBuilder->build($scope);

        $failedFilters = $component->buildFailedFilters();

        $recentCompleted = $this->svc->recentCompleted(self::RECENT_FETCH_LIMIT, $component->selectedClass, $scope);
        $recentFailed = $this->svc->recentFailed(self::RECENT_FETCH_LIMIT, $failedFilters);
        $throughput = $this->svc->hourlyThroughput(24, $scope);

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

            // Backward-chain lineage: the stream entry already carries
            // `parent_uuid` (stamped by RecordJobProcessed). Resolve the
            // parent's class label here so the modal's `↰ From` row can
            // render `(Class)` alongside the uuid. Selection happens
            // before RowEnricher::completed runs on the table list, so we
            // hydrate the modal's single row directly.
            $selectedPayload['parent_class'] = $this->resolveParentClassFor($selectedPayload['parent_uuid'] ?? null);
            $selectedPayload['parent_target'] = $this->resolveParentTargetFor($selectedPayload['parent_uuid'] ?? null);
        }

        if ($selectedFailed !== null) {
            $failedUuid = $selectedFailed['uuid'] ?? null;
            $selectedFailed['wait_ms'] = is_string($failedUuid)
                ? $this->svc->jobWaitMs($failedUuid)
                : null;

            // Backward-chain lineage on failed rows. Unlike the completed
            // path, the failed_jobs row has no parent_uuid column — read
            // the interim `qi:lineage:{uuid}` hash directly, then resolve
            // the parent class from the same uuid → class side-key.
            [$parentUuid, $parentClass] = $this->resolveFailedLineage($failedUuid);
            $selectedFailed['parent_uuid'] = $parentUuid;
            $selectedFailed['parent_class'] = $parentClass;
            $selectedFailed['parent_target'] = $this->resolveParentTargetFor($parentUuid);
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
                $bulkRetryCount = count(FailedJobUuidCollector::collect($failedFilters));
            } catch (Throwable) {
                $bulkRetryCount = null;
            }
        }

        $filterOptions = $this->filterOptionsBuilder->build($classes, $scope);

        $batches = BatchReader::sectionRows($this->svc, $component->expandedBatchId, $scope);

        $pendingEnabled = Config::bool('pending.enabled', true);
        $scopedQueues = $this->svc->configuredQueues($scope);
        $pendingRows = $pendingEnabled ? PendingJobsReader::allPending($scopedQueues, 50) : [];
        $delayedRows = $pendingEnabled ? PendingJobsReader::allDelayed($scopedQueues, 50) : [];
        $inFlightRows = $pendingEnabled ? PendingJobsReader::allInFlight($scopedQueues, 50) : [];

        $selectedPending = $this->modals->selectedPending(
            $component->selectedPendingUuid,
            array_merge($inFlightRows, $pendingRows, $delayedRows),
        );

        if ($selectedPending !== null) {
            // Hydrate parent_class on the in-flight / pending modal.
            // `RecordJobProcessing::copyLineageToPending` stamps
            // parent_uuid onto the pending hash, and PendingJobsReader
            // now surfaces it on the row, but the class label has to be
            // resolved from the `qi:class:{uuid}` side-key — same path
            // the completed + failed modals take.
            $selectedPending['parent_class'] = $this->resolveParentClassFor($selectedPending['parent_uuid'] ?? null);
            $selectedPending['parent_target'] = $this->resolveParentTargetFor($selectedPending['parent_uuid'] ?? null);
        }

        $selectedBatch = $this->modals->selectedBatch($component->expandedBatchId, $batches, $scope);

        // Drive `inert` from the same booleans that actually mount the
        // modals — codex review #1. Routing it off raw selection ids
        // froze the dashboard when an id was set but the modal never
        // mounted (config flip, aged-out row, ?batch= URL with batches
        // disabled).
        // All four conditions key off the resolved selection objects, NOT
        // the component's raw id state. A bookmarked `?batch=` URL with the
        // batch already aged out (or batches disabled), or a stale
        // pendingUuid pointing at a job that's already drained, leaves the
        // raw id set but `selectedPending`/`selectedBatch` null. If we
        // gated `inert` on the raw ids the dashboard would freeze with no
        // modal mounted (codex review).
        $hasOpenModal = $selectedPayload !== null
            || $selectedFailed !== null
            || $selectedPending !== null
            || $selectedBatch !== null;

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

        // Overview-card previews — top-5 of the FULL filtered list (not the
        // paginated slice). Otherwise navigating to page 2 of Completed or
        // Failed and back to Overview would surface page-2 rows in the
        // "Recent" cards instead of the actual most-recent entries.
        $completedPreview = array_slice($completedAll, 0, 5);
        $failedPreview = array_slice($failedAll, 0, 5);

        // Silenced tab — pre-filtered failed + completed rows for classes
        // listed in `queue-insights.silenced`. Skips work entirely when
        // the silenced list is empty so the tab + its data stay zero-cost
        // for hosts that don't use the feature.
        $silencedClasses = resolve(SilencedJobs::class)->all();
        [$silencedFailedRows, $silencedCompletedRows] = $silencedClasses === []
            ? [[], []]
            : $this->buildSilencedListings($silencedClasses, $scope);

        $aggregates = QueueAggregates::aggregate($queues);

        return [
            'scopeConnection' => $scope,
            'connectionNav' => $this->connectionNav->build($scope),
            'activeIssues' => $this->activeIssues->get($scope),
            'snapshotCommandDead' => $this->watchdog->isSnapshotCommandDead($scope),
            'queues' => $queues,
            'totalDepth' => $aggregates['total_depth'],
            'totalInFlight' => $aggregates['total_inflight'],
            'atRisk' => $aggregates['at_risk'],
            'healthy' => $aggregates['healthy'],
            'queuePreview' => QueueAggregates::queuePreview($aggregates['at_risk'], $aggregates['deepest']),
            'pendingPreview' => QueueAggregates::pendingPreview($inFlightRows, $pendingRows, $delayedRows),
            'completedPreview' => $completedPreview,
            'failedPreview' => $failedPreview,
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
            // Top of the chain-navigation back stack — `{class}` label
            // for the "Back to {class}" button in each item modal. Null
            // when the user opened the current modal directly (no
            // chain step to back to). `end()` returns false on empty
            // arrays; coerce to null so the partial sees a clean type.
            'chainBackTop' => $component->chainBackStack === []
                ? null
                : $component->chainBackStack[array_key_last($component->chainBackStack)],
            'silencedClasses' => $silencedClasses,
            'silencedFailedRows' => $silencedFailedRows,
            'silencedCompletedRows' => $silencedCompletedRows,
        ];
    }

    private function buildCompletedFilter(QueueInsightsDashboard $component): CompletedRowFilter
    {
        // Under scope, `recentCompleted` already gates by connection at the
        // stream level — pass empty so the filter doesn't double-gate.
        $connection = $component->scopeConnection !== null
            ? ''
            : $component->completedFilterConnection;

        return new CompletedRowFilter(
            connection: $connection,
            queue: $component->completedFilterQueue,
            from: $component->completedFilterFrom,
            to: $component->completedFilterTo,
            includeSilenced: $component->completedIncludeSilenced,
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

    /**
     * Hydrate parent_class for a single completed-modal row. The completed
     * stream entry already carries `parent_uuid`; we only need the class
     * label here. Returns null when chain lineage is disabled, the
     * parent_uuid is missing, or the parent has aged out of the
     * `qi:class:{uuid}` window.
     */
    private function resolveParentClassFor(mixed $parentUuid): ?string
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return null;
        }

        if (! is_string($parentUuid) || $parentUuid === '') {
            return null;
        }

        return ParentClassResolver::resolve($parentUuid);
    }

    /**
     * Hydrate the click-through target for the chain-lineage `↰ From`
     * row. Returns a `{type, id}` tuple the modal partial wires onto a
     * `wire:click="openByUuid(...)"` button when present, or null when
     * the parent has aged out of every retention window (the partial
     * falls back to plain text + copy in that case).
     *
     * @return array{type: string, id: int|string}|null
     */
    private function resolveParentTargetFor(mixed $parentUuid): ?array
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return null;
        }

        if (! is_string($parentUuid) || $parentUuid === '') {
            return null;
        }

        return UuidResolver::resolve($parentUuid);
    }

    /**
     * Hydrate (parent_uuid, parent_class) for a single failed-modal row.
     * Failed_jobs has no parent_uuid column, so we read the interim
     * `qi:lineage:{uuid}` directly. Both fields are null when chain
     * lineage is off, the child uuid is missing, or the lineage hash has
     * already aged out.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveFailedLineage(mixed $childUuid): array
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return [null, null];
        }

        if (! is_string($childUuid) || $childUuid === '') {
            return [null, null];
        }

        $parentUuid = (new ChainLineageStore())->readLineage($childUuid);
        if ($parentUuid === null) {
            return [null, null];
        }

        return [$parentUuid, ParentClassResolver::resolve($parentUuid)];
    }

    /**
     * Build the Silenced-tab payload — failed_jobs rows + completed-stream
     * rows for classes listed in `queue-insights.silenced`. One per-class
     * fetch with `includeSilenced=true` so the silenced exclusion is
     * bypassed; results are merged in the caller-controlled order
     * (silenced classes' configured order).
     *
     * Capped at PER_PAGE per axis — the Silenced tab is a roster, not a
     * paginated list. Operators who need deep history land on the main
     * Failed/Completed pane and toggle "Show silenced".
     *
     * @param  list<string>  $silencedClasses
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildSilencedListings(array $silencedClasses, ?string $scope): array
    {
        $failedRows = [];
        $completedRows = [];

        foreach ($silencedClasses as $class) {
            $rowsForClass = $this->svc->recentFailed(
                self::PER_PAGE,
                new FailedJobFilters(
                    connection: $scope ?? '',
                    class: $class,
                    includeSilenced: true,
                ),
            );
            foreach ($rowsForClass as $row) {
                $failedRows[] = $row;
            }

            $completedForClass = $this->svc->recentCompleted(self::PER_PAGE, $class, $scope);
            foreach ($completedForClass as $row) {
                if (($row['class'] ?? null) === $class) {
                    $completedRows[] = $row;
                }
            }
        }

        // Order by failed_at / processed_at descending, slice to PER_PAGE
        // so the tab caps at one page per axis.
        usort($failedRows, static fn (array $a, array $b): int => strcmp(
            is_string($b['failed_at'] ?? null) ? $b['failed_at'] : '',
            is_string($a['failed_at'] ?? null) ? $a['failed_at'] : '',
        ));
        usort($completedRows, static fn (array $a, array $b): int => strcmp(
            is_string($b['processed_at'] ?? null) ? $b['processed_at'] : '',
            is_string($a['processed_at'] ?? null) ? $a['processed_at'] : '',
        ));

        $failedRows = array_slice($failedRows, 0, self::PER_PAGE);
        $completedRows = array_slice($completedRows, 0, self::PER_PAGE);

        return [RowEnricher::failed($failedRows), RowEnricher::completed($completedRows)];
    }
}
