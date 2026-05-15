<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
use SanderMuller\QueueInsights\Support\HorizonNotRunning;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Support\QueueAggregates;
use SanderMuller\QueueInsights\Support\QueueScopeKey;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Support\SilencedJobs;
use SanderMuller\QueueInsights\Support\UuidResolver;
use SanderMuller\QueueInsights\Support\WaitTimeMetrics;
use Throwable;

/** @internal */
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
        'horizonNotRunning',
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
        'selectedQueue',
        'selectedQueueConnection',
        'selectedQueueName',
        'selectedPayload',
        'selectedPayloadId',
        'selectedFailed',
        'selectedFailedId',
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
        'silencedFailedPaginator',
        'silencedCompletedPaginator',
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
        private HorizonNotRunning $horizonNotRunning,
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

        // Job drained while its pending/in-flight modal stayed open — follow
        // the uuid to its terminal surface so the modal swaps in place to the
        // completed / failed view instead of degrading to the "no longer
        // pending" empty state. Runs before the selection resolvers below so
        // the re-pointed `selectedPayloadId` / `selectedFailedId` mount in
        // this same render pass.
        $this->followDrainedPendingModal($component);

        $queues = $this->queueRowsBuilder->build($component->expandedQueueKey, $scope);
        $classes = $this->classRowsBuilder->build($scope);

        $failedFilters = $component->buildFailedFilters();

        $completedReadConnection = $this->resolveCompletedReadConnection($component, $scope);
        $recentCompleted = $this->svc->recentCompleted(self::RECENT_FETCH_LIMIT, $component->selectedClass, $completedReadConnection);
        $recentFailed = $this->svc->recentFailed(self::RECENT_FETCH_LIMIT, $failedFilters);
        $throughput = $this->svc->hourlyThroughput(24, $scope);

        $selectedPayload = $this->modals->selectedPayload($component->selectedPayloadId, $recentCompleted);

        // Class-filter bypass for `selectedPayloadId`. The primary read
        // above is class-filtered to drive the Completed table, but the
        // selection id can be set independently of the active class filter
        // — `followDrainedPendingModal` can re-point a drained pending
        // modal to any uuid's completed surface, and a future deep link
        // could do the same. Without this fallback, the operator sees the
        // stale modal's "no longer available" empty state even though the
        // entry is alive and only hidden by an unrelated class filter.
        if ($selectedPayload === null
            && $component->selectedPayloadId !== null
            && $component->selectedClass !== null
        ) {
            $unfilteredCompleted = $this->svc->recentCompleted(self::RECENT_FETCH_LIMIT, null, $completedReadConnection);
            $selectedPayload = $this->modals->selectedPayload($component->selectedPayloadId, $unfilteredCompleted);
        }

        $selectedFailed = $this->resolveSelectedFailed($component, $recentFailed);

        // Decorate the selected rows with the per-job wait sample. Modals
        // render `Wait: —` when this is null (legacy job pre-dating the
        // JobQueued listener, or a driver that omits payload.uuid).
        if ($selectedPayload !== null) {
            $selectedPayload = $this->decorateSelectedPayload($selectedPayload);
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

        $batchesEnabled = Config::bool('batches.enabled', true);
        $batches = BatchReader::sectionRows($this->svc, $component->expandedBatchId, $scope);

        $pendingEnabled = Config::bool('pending.enabled', true);
        $pendingFetchQueues = $this->resolvePendingFetchQueues($component, $scope);

        $pendingRows = $pendingEnabled ? PendingJobsReader::allPending($pendingFetchQueues, 50) : [];
        $delayedRows = $pendingEnabled ? PendingJobsReader::allDelayed($pendingFetchQueues, 50) : [];
        $inFlightRows = $pendingEnabled ? PendingJobsReader::allInFlight($pendingFetchQueues, 50) : [];

        [$inFlightRows, $pendingRows, $delayedRows] = $this->scopeAndHydratePendingRows(
            $component,
            $inFlightRows,
            $pendingRows,
            $delayedRows,
        );

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

        // Drive `inert` from the exact `@if` conditions that mount each
        // modal in dashboard.blade.php — codex review #1. Every selection id
        // that's set now mounts *something*: the real modal when the record
        // resolves, or `<stale-modal>` when it aged out (trimmed stream
        // entry, pruned failed_jobs row, pending tracking disabled). So the
        // completed / failed / pending terms key off the raw id — not the
        // resolved object, which would leave `inert` false while a stale
        // modal is visibly mounted. The batch term keeps its `batchesEnabled`
        // guard: a `?batch=` URL with batches disabled mounts no modal at
        // all, so the dashboard must stay interactive.
        $hasOpenModal = $component->selectedPayloadId !== null
            || $component->selectedFailedId !== null
            || $component->selectedPendingUuid !== null
            || ($batchesEnabled && $component->expandedBatchId !== '');

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

        $silencedView = $this->buildSilencedView($scope, $component);
        $silencedClasses = $silencedView['classes'];
        $silencedPatterns = $silencedView['patterns'];
        $silencedFailedRows = $silencedView['failed_rows'];
        $silencedCompletedRows = $silencedView['completed_rows'];
        $silencedFailedPaginator = $silencedView['failed_paginator'];
        $silencedCompletedPaginator = $silencedView['completed_paginator'];

        $aggregates = QueueAggregates::aggregate($queues);

        return [
            'scopeConnection' => $scope,
            'connectionNav' => $this->connectionNav->build($scope),
            'activeIssues' => $this->activeIssues->get($scope),
            'snapshotCommandDead' => $this->watchdog->isSnapshotCommandDead($scope),
            'horizonNotRunning' => $this->horizonNotRunning->isNotRunning(),
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
            'selectedQueue' => $component->selectedQueue,
            // Decomposed parts surfaced for the inline scope strip in
            // dashboard.blade.php; both empty when no queue scope is active.
            'selectedQueueConnection' => QueueScopeKey::decompose($component->selectedQueue)['connection'] ?? '',
            'selectedQueueName' => QueueScopeKey::decompose($component->selectedQueue)['queue'] ?? '',
            'selectedPayload' => $selectedPayload,
            // Raw selection ids surfaced alongside the resolved objects so
            // dashboard.blade.php can fall back to <stale-modal> when an id
            // is set but its record aged out (resolved object is null).
            'selectedPayloadId' => $component->selectedPayloadId,
            'selectedFailed' => $selectedFailed,
            'selectedFailedId' => $component->selectedFailedId,
            'payloadTab' => $component->payloadTab,
            'throughput' => $throughput,
            'stats' => $this->headlineStatsBuilder->build($throughput, $queues, $classes),
            'pendingGapWarnThreshold' => Config::int('pending.gap_warn_threshold', 5),
            'failedFiltersActive' => ! $failedFilters->isEmpty(),
            'canRetry' => $canRetry,
            'bulkRetryCount' => $bulkRetryCount,
            'batches' => $batches,
            'batchesEnabled' => $batchesEnabled,
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
            'silencedFailedPaginator' => $silencedFailedPaginator,
            'silencedCompletedPaginator' => $silencedCompletedPaginator,
        ];
    }

    /**
     * Swap a drained pending/in-flight modal to its terminal surface.
     *
     * When the operator keeps the pending modal open and a worker finishes
     * the job, `RecordJobProcessed` / `RecordJobFailed` delete the
     * `qi:pending:{uuid}` hash — the modal would otherwise degrade to the
     * "no longer pending" empty state on the next poll. Instead, follow the
     * uuid to whichever surface it landed on (completed stream / failed_jobs
     * row) and re-point the component's selection so the modal swaps in
     * place to the completed / failed view.
     *
     * No-op while the job is still pending or in-flight (the hash is
     * present), when chain-lineage is disabled (no uuid index to follow),
     * and when the uuid has aged out of every retention window — the
     * existing empty-state fallback stands in those cases.
     */
    private function followDrainedPendingModal(QueueInsightsDashboard $component): void
    {
        $uuid = $component->selectedPendingUuid;

        if ($uuid === null) {
            return;
        }

        // Still pending / in-flight — the hash is intact, leave the modal be.
        // `findPendingByUuid` is the same per-uuid lookup `ModalResolver`
        // falls back to, so a job inside or outside the visible window is
        // treated identically here.
        if ($this->svc->findPendingByUuid($uuid) !== null) {
            return;
        }

        // The `uuid-completed` / `uuid-failed` indexes UuidResolver follows
        // are only written when chain-lineage is enabled. Skip the Redis
        // probes entirely when it's off — the modal keeps its empty-state
        // fallback.
        if (! Config::bool('chain_lineage.enabled', true)) {
            return;
        }

        $target = UuidResolver::resolve($uuid);

        // null  → aged out of every retention window; keep the empty state.
        // pending → a race re-created the hash between the lookup above and
        //           the resolve; leave the pending modal selection intact.
        if ($target === null || $target['type'] === 'pending') {
            return;
        }

        // Retry-race guard: `uuid-completed` / `uuid-failed` indexes outlive
        // the pending hash by their lineage TTL. If an operator retries the
        // failed row in the gap between the pre-check above and
        // `UuidResolver::resolve()`, the resolver — which checks the terminal
        // indexes BEFORE the live pending hash — returns `'completed'` /
        // `'failed'` even though the uuid has been re-queued and is pending
        // again. Re-probe the pending hash at decision time so the live
        // retried job is never yanked to its stale terminal modal.
        if ($this->svc->findPendingByUuid($uuid) !== null) {
            return;
        }

        // Re-point the selection. `selectedPendingUuid` is cleared so the
        // pending modal unmounts; the completed / failed id drives the
        // matching modal in this same render pass. The chain back stack is
        // intentionally untouched — this is an automatic in-place swap of a
        // drained job, not a user `↰ From` navigation step.
        $component->selectedPendingUuid = null;

        match ($target['type']) {
            'completed' => $component->selectedPayloadId = (string) $target['id'],
            'failed' => $component->selectedFailedId = (int) $target['id'],
        };
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
        $queue = $component->completedFilterQueue;

        // Global queue-scope (`?qk={conn}:{queue}`) overrides per-pane filters.
        // Rejected outright when path-level scope is set AND points to a
        // different connection — same isolation guard as
        // `resolvePendingFetchQueues`. Mirrors the routing in
        // `QueueInsightsDashboard::buildFailedFilters`.
        $queueScope = QueueScopeKey::decompose($component->selectedQueue);
        $pathScope = $component->scopeConnection;
        if ($queueScope !== null && ($pathScope === null || $queueScope['connection'] === $pathScope)) {
            $connection = $pathScope !== null ? '' : $queueScope['connection'];
            $queue = $queueScope['queue'];
        }

        // Auto-reveal when the active class scope is silenced — same rationale
        // as `QueueInsightsDashboard::buildFailedFilters`. Without this the
        // completed list reads as empty after clicking a silenced row on the
        // Classes tab.
        $includeSilenced = $component->completedIncludeSilenced
            || ($component->selectedClass !== null
                && resolve(SilencedJobs::class)->isSilenced($component->selectedClass));

        return new CompletedRowFilter(
            connection: $connection,
            queue: $queue,
            from: $component->completedFilterFrom,
            to: $component->completedFilterTo,
            includeSilenced: $includeSilenced,
        );
    }

    private function completedFiltersActive(QueueInsightsDashboard $component): bool
    {
        return $component->selectedClass !== null
            || $component->selectedQueue !== ''
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
     * Paginated per axis — silenced classes are typically the spammiest
     * traffic (vendor pings, retry storms). Earlier design capped each
     * axis at one page and pushed operators to the main lists with
     * "Show silenced" toggled; that read like an empty roster on busy
     * systems.
     *
     * @return array{
     *     classes: list<string>,
     *     patterns: list<string>,
     *     failed_rows: list<array<string, mixed>>,
     *     completed_rows: list<array<string, mixed>>,
     *     failed_paginator: LengthAwarePaginator<int, array<string, mixed>>,
     *     completed_paginator: LengthAwarePaginator<int, array<string, mixed>>,
     * }
     */
    private function buildSilencedView(?string $scope, QueueInsightsDashboard $component): array
    {
        $silenced = resolve(SilencedJobs::class);
        $classes = $silenced->all();
        $patterns = $silenced->patterns();

        if (! $silenced->hasAny()) {
            return [
                'classes' => $classes,
                'patterns' => $patterns,
                'failed_rows' => [],
                'completed_rows' => [],
                'failed_paginator' => new LengthAwarePaginator([], 0, self::PER_PAGE, 1, ['pageName' => 'sfp']),
                'completed_paginator' => new LengthAwarePaginator([], 0, self::PER_PAGE, 1, ['pageName' => 'scp']),
            ];
        }

        $silencedFailed = $this->filterSilenced(
            $silenced,
            RowEnricher::failed($this->svc->recentFailed(
                self::RECENT_FETCH_LIMIT,
                new FailedJobFilters(connection: $scope ?? '', includeSilenced: true),
            )),
        );
        $silencedCompleted = $this->filterSilenced(
            $silenced,
            RowEnricher::completed($this->svc->recentCompleted(self::RECENT_FETCH_LIMIT, null, $scope)),
        );

        [$failedPaginator, $failedRowsPaged] = $this->paginate(
            $silencedFailed,
            $component->silencedFailedPerPage,
            $component->silencedFailedPage,
            'sfp',
        );
        [$completedPaginator, $completedRowsPaged] = $this->paginate(
            $silencedCompleted,
            $component->silencedCompletedPerPage,
            $component->silencedCompletedPage,
            'scp',
        );

        return [
            'classes' => $classes,
            'patterns' => $patterns,
            'failed_rows' => $failedRowsPaged,
            'completed_rows' => $completedRowsPaged,
            'failed_paginator' => $failedPaginator,
            'completed_paginator' => $completedPaginator,
        ];
    }

    /**
     * Resolve the failed-job modal's selected row. First search the visible
     * `$recentFailed` list; on miss (typical for silenced rows that the SQL
     * exclusion strips), fall back to a direct lookup by id and re-enrich.
     *
     * @param  list<array<array-key, mixed>>  $recentFailed
     * @return array<array-key, mixed>|null
     */
    private function resolveSelectedFailed(QueueInsightsDashboard $component, array $recentFailed): ?array
    {
        $selectedFailed = $this->modals->selectedFailed($component->selectedFailedId, $recentFailed);

        if ($selectedFailed !== null || $component->selectedFailedId === null) {
            return $selectedFailed;
        }

        try {
            $row = DB::table('failed_jobs')
                ->where('id', $component->selectedFailedId)
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        // Re-enforce active scope on the fallback path. Without this, a deep-
        // linked or forged `selectedFailedId` could load a row from a
        // different connection/queue than the operator's scoped dashboard
        // is supposed to expose — silently bypassing the path-level scope.
        // The fallback is meant to surface silenced rows that the SQL filter
        // strips, NOT to widen the connection/queue surface.
        $rowArray = (array) $row;
        if ($component->scopeConnection !== null && ($rowArray['connection'] ?? null) !== $component->scopeConnection) {
            return null;
        }

        $queueScope = QueueScopeKey::decompose($component->selectedQueue);
        if ($queueScope !== null
            && (($rowArray['connection'] ?? null) !== $queueScope['connection']
                || ($rowArray['queue'] ?? null) !== $queueScope['queue'])
        ) {
            return null;
        }

        $enriched = RowEnricher::failed([$rowArray]);

        return $enriched[0] ?? null;
    }

    /**
     * Pick the connection passed to `recentCompleted` so the read window is
     * narrowed BEFORE truncation. Path-level scope takes precedence; falls
     * back to the connection embedded in `selectedQueue` so a queue-only
     * scope still routes through `completed:connection:{conn}` (~10 k cap)
     * instead of the global aggregate (~250 cap). Returns null when neither
     * scope is active — the global stream is the right read in that case.
     */
    private function resolveCompletedReadConnection(QueueInsightsDashboard $component, ?string $scope): ?string
    {
        if ($scope !== null) {
            return $scope;
        }

        return QueueScopeKey::decompose($component->selectedQueue)['connection'] ?? null;
    }

    /**
     * Pick the queue tuples passed to `PendingJobsReader::all*`. Pending /
     * in-flight aggregation caps at 50 candidates GLOBALLY across configured
     * queues — if only post-filter narrowed by `selectedQueue`, a busy
     * unrelated queue could consume the candidate window and leave the
     * scoped queue empty. Pushing the scope INTO the read targets the
     * ZRANGEBYSCORE at the selected queue's zset directly.
     *
     * @return list<array{connection: string, queue: string}>
     */
    private function resolvePendingFetchQueues(QueueInsightsDashboard $component, ?string $scope): array
    {
        $queueScope = QueueScopeKey::decompose($component->selectedQueue);

        // Reject queue-scope when path-level scope is set AND points to a
        // different connection — a forged `?qk=sqs:reports` on a route scoped
        // to `redis` would otherwise read the foreign connection's pending
        // zsets and leak rows into the scoped dashboard. Path scope wins.
        if ($queueScope !== null && ($scope === null || $queueScope['connection'] === $scope)) {
            return [$queueScope];
        }

        return $this->svc->configuredQueues($scope);
    }

    /**
     * Apply the global class + queue scope to the pending lists post-fetch and
     * bulk-hydrate `parent_class` for any row that carries a `parent_uuid`
     * (chain-lineage). One MGET round-trip across all three lists keeps the
     * chain chip in `pending-row.blade.php` visually consistent with completed
     * + failed (renders the parent class label, not just "chain").
     *
     * Returns the three filtered + hydrated lists in the input order
     * (`[inFlight, pending, delayed]`). Filtering at this stage also narrows
     * the overview pane's `pendingPreview`, which is built from the same
     * arrays.
     *
     * @param  list<array<string, mixed>>  $inFlightRows
     * @param  list<array<string, mixed>>  $pendingRows
     * @param  list<array<string, mixed>>  $delayedRows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function scopeAndHydratePendingRows(
        QueueInsightsDashboard $component,
        array $inFlightRows,
        array $pendingRows,
        array $delayedRows,
    ): array {
        if ($component->selectedClass !== null) {
            $selectedClass = $component->selectedClass;
            $byClass = static fn (array $r): bool => ($r['class'] ?? null) === $selectedClass;
            $pendingRows = array_values(array_filter($pendingRows, $byClass));
            $delayedRows = array_values(array_filter($delayedRows, $byClass));
            $inFlightRows = array_values(array_filter($inFlightRows, $byClass));
        }

        $queueScope = QueueScopeKey::decompose($component->selectedQueue);
        $scope = $component->scopeConnection;
        // Mirror the fetch-side rejection in `resolvePendingFetchQueues`: a
        // forged `?qk=` whose connection differs from the path-scope is
        // ignored so the post-filter doesn't zero-out rows the operator is
        // entitled to see (and doesn't paper over a fetch-side leak).
        if ($queueScope !== null && ($scope === null || $queueScope['connection'] === $scope)) {
            $selConn = $queueScope['connection'];
            $selQueue = $queueScope['queue'];
            $byQueue = static fn (array $r): bool => ($r['connection'] ?? null) === $selConn
                && ($r['queue'] ?? null) === $selQueue;
            $pendingRows = array_values(array_filter($pendingRows, $byQueue));
            $delayedRows = array_values(array_filter($delayedRows, $byQueue));
            $inFlightRows = array_values(array_filter($inFlightRows, $byQueue));
        }

        $allParentUuids = [];
        foreach ([$inFlightRows, $pendingRows, $delayedRows] as $rows) {
            foreach ($rows as $r) {
                $p = $r['parent_uuid'] ?? null;
                if (is_string($p) && $p !== '') {
                    $allParentUuids[$p] = true;
                }
            }
        }

        $parentClassMap = $allParentUuids === []
            ? []
            : ParentClassResolver::resolveMany(array_keys($allParentUuids));

        return [
            $this->stampParentClass($inFlightRows, $parentClassMap),
            $this->stampParentClass($pendingRows, $parentClassMap),
            $this->stampParentClass($delayedRows, $parentClassMap),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $parentClassMap
     * @return list<array<string, mixed>>
     */
    private function stampParentClass(array $rows, array $parentClassMap): array
    {
        $out = [];
        foreach ($rows as $row) {
            $p = $row['parent_uuid'] ?? null;
            $row['parent_class'] = is_string($p) && $p !== '' && isset($parentClassMap[$p])
                ? $parentClassMap[$p]
                : null;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $selectedPayload
     * @return array<string, mixed>
     */
    private function decorateSelectedPayload(array $selectedPayload): array
    {
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

        return $selectedPayload;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterSilenced(SilencedJobs $silenced, array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($silenced): bool {
                $class = $row['class'] ?? null;

                return is_string($class) && $silenced->isSilenced($class);
            },
        ));
    }
}
