<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Pagination\LengthAwarePaginator;
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
     * Default per-page count for the Completed and Failed lists. The
     * operator can override per-tab via the per-page dropdown; URL
     * params (`cpp` / `fpp`) round-trip the choice. Page is clamped
     * to the available range at render time.
     *
     * Default of 10 keeps the tab content above-fold-friendly on a
     * laptop viewport — operators who want denser tables pick 25/50/100
     * from the dropdown and the choice persists via URL.
     */
    public const int PER_PAGE = 10;

    /**
     * Whitelist of acceptable per-page values. Driven by the dropdown
     * in `partials/pagination-controls.blade.php`; also enforced by
     * `QueueInsightsDashboard::updated()` so a hostile `?cpp=999999`
     * URL param can't force an unbounded slice.
     *
     * Keep this small — every option must produce a usable page count
     * within `RECENT_FETCH_LIMIT` (250). 100/page = 2 full pages + a
     * tail, which is the upper bound that still feels paginated.
     */
    public const array PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Underlying fetch ceiling for the Completed + Failed tabs. Pages
     * are sliced from this set, so this caps how deep the user can
     * paginate ("recent {RECENT_FETCH_LIMIT} jobs" — older history is
     * not paginated by design). Sized so the largest per-page option
     * still produces multiple pages.
     */
    public const int RECENT_FETCH_LIMIT = 250;

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
        'completedPaginator',
        'completedFiltersActive',
        'failedRows',
        'failedTotal',
        'failedPage',
        'failedTotalPages',
        'failedPerPage',
        'failedPaginator',
        'perPageOptions',
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
        'silencedPatterns',
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

        // Server-side pagination — see paginate() for the per-axis logic.
        $completedAll = $this->buildCompletedFilter($component)->apply(RowEnricher::completed($recentCompleted));
        $failedAll = RowEnricher::failed($recentFailed);

        [$completedPaginator, $completedRowsPaged, $completedPage, $completedTotal, $completedTotalPages, $completedPerPage] =
            $this->paginate($completedAll, $component->completedPerPage, $component->completedPage, 'cp');
        [$failedPaginator, $failedRowsPaged, $failedPage, $failedTotal, $failedTotalPages, $failedPerPage] =
            $this->paginate($failedAll, $component->failedPerPage, $component->failedPage, 'fp');

        // Snap the clamped page values back onto the Livewire props so the
        // URL reflects the actually-rendered page. A hostile `?cp=99999`
        // deep-link would otherwise render the last-available page but
        // leave the URL pinned to 99999 — bookmarks + share-links go stale.
        $component->completedPage = $completedPage;
        $component->failedPage = $failedPage;

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
        $silenced = resolve(SilencedJobs::class);
        $silencedClasses = $silenced->all();
        $silencedPatterns = $silenced->patterns();
        [$silencedFailedRows, $silencedCompletedRows] = $silenced->hasAny()
            ? $this->buildSilencedListings($silenced, $scope)
            : [[], []];

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
            'completedPerPage' => $completedPerPage,
            'completedPaginator' => $completedPaginator,
            'completedFiltersActive' => $this->completedFiltersActive($component),
            'failedRows' => $failedRowsPaged,
            'failedTotal' => $failedTotal,
            'failedPage' => $failedPage,
            'failedTotalPages' => $failedTotalPages,
            'failedPerPage' => $failedPerPage,
            'failedPaginator' => $failedPaginator,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
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
            'silencedPatterns' => $silencedPatterns,
            'silencedFailedRows' => $silencedFailedRows,
            'silencedCompletedRows' => $silencedCompletedRows,
        ];
    }

    /**
     * Belt-and-suspenders clamp for per-page values. The component's
     * `updated()` hook already snaps user input back to PER_PAGE_OPTIONS,
     * but values bound straight from `mount()` (URL params on first load)
     * skip that hook — guard here so a `?cpp=999999` deep link can't slip
     * through to `array_slice($all, 0, 999999)`.
     */
    private function resolvePerPage(int $candidate): int
    {
        return in_array($candidate, self::PER_PAGE_OPTIONS, true)
            ? $candidate
            : self::PER_PAGE;
    }

    /**
     * Slice + paginator construction for one axis (Completed or Failed).
     * Extracted from `build()` to keep that method under PHPStan's
     * cognitive-complexity ceiling (mirrors the existing private-helper
     * pattern used by `buildCompletedFilter`, `resolveParentClassFor`).
     *
     * Returns a 6-tuple to keep the call site explicit: paginator, the
     * sliced page array, the clamped current page, total row count,
     * total page count, and the resolved per-page (after PER_PAGE_OPTIONS
     * clamp). Callers destructure for use in the view-data return.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: LengthAwarePaginator<int, array<string, mixed>>, 1: list<array<string, mixed>>, 2: int, 3: int, 4: int, 5: int}
     */
    private function paginate(array $rows, int $perPageProp, int $pageProp, string $pageName): array
    {
        $perPage = $this->resolvePerPage($perPageProp);
        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $pageProp), $totalPages);
        $sliced = array_slice($rows, ($page - 1) * $perPage, $perPage);

        // LengthAwarePaginator wraps the already-sliced page so views can
        // use `->onFirstPage()`, `->hasMorePages()`, `->firstItem()`, etc.
        // The standard `->links()` view is NOT used — `partials/pagination-
        // controls` is the package's own Tailwind-themed footer (drives
        // `gotoFooPage` Livewire methods to keep the URL scheme stable).
        $paginator = new LengthAwarePaginator(
            items: $sliced,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['pageName' => $pageName],
        );

        return [$paginator, $sliced, $page, $total, $totalPages, $perPage];
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
     * rows for classes listed in `queue-insights.silenced`.
     *
     * Two single-shot fetches (one per axis, both with the silenced
     * exclusion bypassed), then post-filter to silenced classes only. This
     * collapses what was an O(N) per-class fetch loop into a constant
     * 2 round-trips regardless of how many classes are silenced — operators
     * with 10+ silenced classes don't pay N×2 read amplification on every
     * 10s dashboard poll.
     *
     * Capped at PER_PAGE per axis — the Silenced tab is a roster, not a
     * paginated archive. Operators who need deep history land on the main
     * Failed / Completed pane and toggle "Show silenced".
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildSilencedListings(SilencedJobs $silenced, ?string $scope): array
    {
        $allFailed = RowEnricher::failed($this->svc->recentFailed(
            self::RECENT_FETCH_LIMIT,
            new FailedJobFilters(connection: $scope ?? '', includeSilenced: true),
        ));
        $allCompleted = RowEnricher::completed(
            $this->svc->recentCompleted(self::RECENT_FETCH_LIMIT, null, $scope),
        );

        $silencedFailed = array_values(array_filter(
            $allFailed,
            static function (array $row) use ($silenced): bool {
                $class = $row['class'] ?? null;

                return is_string($class) && $silenced->isSilenced($class);
            },
        ));
        $silencedCompleted = array_values(array_filter(
            $allCompleted,
            static function (array $row) use ($silenced): bool {
                $class = $row['class'] ?? null;

                return is_string($class) && $silenced->isSilenced($class);
            },
        ));

        return [
            array_slice($silencedFailed, 0, self::PER_PAGE),
            array_slice($silencedCompleted, 0, self::PER_PAGE),
        ];
    }
}
