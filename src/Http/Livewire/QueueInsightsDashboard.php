<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View as ViewFactory;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\BatchReader;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\QueueAggregates;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Support\WaitTimeMetrics;
use Throwable;

#[Layout('queue-insights::layouts.app')]
final class QueueInsightsDashboard extends Component
{
    #[Url(as: 'ck')]
    public ?string $selectedClass = null;

    public ?string $selectedPayloadId = null;

    public ?int $selectedFailedId = null;

    public ?string $selectedPendingUuid = null;

    public string $payloadTab = 'raw';

    /*
     * Failed-jobs filter state. Each #[Url] prop shares to the query string
     * (short keys to keep URLs scannable). Empty string = "no filter on
     * that field" — see Support\FailedJobFilters for the semantics.
     */

    #[Url(as: 'fc', except: '')]
    public string $filterConnection = '';

    #[Url(as: 'fq', except: '')]
    public string $filterQueue = '';

    #[Url(as: 'fk', except: '')]
    public string $filterClass = '';

    #[Url(as: 'ffrom', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'fto', except: '')]
    public string $filterTo = '';

    /*
     * Recent-completed filter state. Class filter routes through `selectedClass`
     * (existing pre-fetch namespacing in QueueInsights::recentCompleted); the
     * other four are post-fetch PHP filters over the 50-row default cap.
     */

    #[Url(as: 'cc', except: '')]
    public string $completedFilterConnection = '';

    #[Url(as: 'cqu', except: '')]
    public string $completedFilterQueue = '';

    #[Url(as: 'cfrom', except: '')]
    public string $completedFilterFrom = '';

    #[Url(as: 'cto', except: '')]
    public string $completedFilterTo = '';

    /*
     * Pagination — completed + failed lists. URL-shareable (`cp`/`fp`) so a
     * deep-linked page survives refresh. Per-page is fixed at 25 to keep the
     * tab content above-fold-friendly; ramp up if the host app reports it
     * feels too small. Page is clamped to the available range at render time
     * so bookmarking page 5 of a list that's since shrunk to 2 pages still
     * lands on page 2 instead of an empty view.
     */

    private const int PER_PAGE = 25;

    #[Url(as: 'cp', except: 1)]
    public int $completedPage = 1;

    #[Url(as: 'fp', except: 1)]
    public int $failedPage = 1;

    /*
     * Pending-jobs inspector — single-queue expand state. Format:
     * "{connection}:{canonical-queue}". Empty string = nothing expanded.
     * URL-shareable so an operator can paste the dashboard URL and land
     * on a peer's expanded inspector view.
     */

    #[Url(as: 'qopen', except: '')]
    public string $expandedQueueKey = '';

    /*
     * Batches inspector — single-batch expand state. URL-shareable so an
     * operator can paste the dashboard URL and land on a peer's expanded
     * batch view. Empty string = nothing expanded.
     */

    #[Url(as: 'batch', except: '')]
    public string $expandedBatchId = '';

    /**
     * Defense-in-depth: enforce the `viewQueueInsights` Gate on component mount,
     * not just on the bundled route. A host app that embeds the component in a
     * publicly-reachable view would otherwise leak queue insights.
     */
    public function mount(): void
    {
        if (Gate::has('viewQueueInsights')) {
            Gate::authorize('viewQueueInsights');
        }
    }

    public function selectClass(?string $class = null): void
    {
        $this->selectedClass = $class;
    }

    public function clearSelectedClass(): void
    {
        $this->selectedClass = null;
    }

    public function openPayload(string $id): void
    {
        $this->selectedPayloadId = $id;
        // Reset tab to the default on every open so users who flipped to JSON on a
        // prior modal see the default Raw KV view first on the next row.
        $this->payloadTab = 'raw';
        // expandedBatchId is intentionally preserved — opening an item from the
        // batch modal stacks the item modal on top, and closing it (close*)
        // returns the user to the batch view. Dashboard.blade.php renders the
        // batch modal first so the item modal sits visually on top.
    }

    public function closePayload(): void
    {
        $this->selectedPayloadId = null;
    }

    public function openFailed(int $id): void
    {
        $this->selectedFailedId = $id;
    }

    public function closeFailed(): void
    {
        $this->selectedFailedId = null;
    }

    public function openPending(string $uuid): void
    {
        // Pending row → modal. The dashboard's render() re-reads the
        // pending hash by uuid each poll, so a worker grabbing the job
        // mid-modal degrades to an empty `selectedPending` and the modal
        // shows the "no longer pending" empty state on the next poll.
        $this->selectedPendingUuid = $uuid;
    }

    public function closePending(): void
    {
        $this->selectedPendingUuid = null;
    }

    /**
     * Open a batch from an item context (chip click in details/failed/pending
     * modal, or any other "go to this batch" affordance). Closes any open item
     * modal so only the batch modal remains visible — distinct from
     * `toggleBatchInspector`, which is the row-toggle on the Batches section
     * and intentionally toggles open/close.
     */
    public function openBatch(string $id): void
    {
        if ($id === '') {
            return;
        }

        $this->selectedPayloadId = null;
        $this->selectedFailedId = null;
        $this->selectedPendingUuid = null;
        $this->expandedBatchId = $id;
    }

    public function setPayloadTab(string $tab): void
    {
        if (in_array($tab, ['json', 'raw'], true)) {
            $this->payloadTab = $tab;
        }
    }

    public function clearFailedFilters(): void
    {
        $this->filterConnection = '';
        $this->filterQueue = '';
        $this->filterClass = '';
        $this->filterFrom = '';
        $this->filterTo = '';
        $this->failedPage = 1;
    }

    public function gotoCompletedPage(int $page): void
    {
        $this->completedPage = max(1, $page);
    }

    public function gotoFailedPage(int $page): void
    {
        $this->failedPage = max(1, $page);
    }

    /**
     * Reset pagination when a filter changes — bookmarked page numbers stop
     * making sense the moment the underlying set shifts. Caught for any
     * Livewire-tracked filter by name prefix instead of one hook per prop.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'completedFilter') || $name === 'selectedClass') {
            $this->completedPage = 1;
        } elseif (str_starts_with($name, 'filter')) {
            $this->failedPage = 1;
        }
    }

    public function toggleQueueInspector(string $key): void
    {
        // Single-queue expand keeps render() costs bounded — only one set of
        // pendingJobs / delayedJobs round-trips per poll. Multi-open is an
        // operator request away if it ever lands on the roadmap.
        $this->expandedQueueKey = $this->expandedQueueKey === $key ? '' : $key;
    }

    public function toggleBatchInspector(string $id): void
    {
        // Single-batch expand mirrors toggleQueueInspector: only the expanded
        // row pays the per-uuid hydration cost on each 10s poll.
        $this->expandedBatchId = $this->expandedBatchId === $id ? '' : $id;
    }

    public function closeBatch(): void
    {
        // Unconditional close — distinct from `toggleBatchInspector` because
        // the modal's backdrop / X / Esc bindings need a non-toggle exit. If
        // they routed through toggle, a race-rendered empty modal (where the
        // mounted batch id was lost) would flip the prop to an arbitrary
        // value instead of closing.
        $this->expandedBatchId = '';
    }

    public function clearCompletedFilters(): void
    {
        $this->selectedClass = null;
        $this->completedFilterConnection = '';
        $this->completedFilterQueue = '';
        $this->completedFilterFrom = '';
        $this->completedFilterTo = '';
        $this->completedPage = 1;
    }

    private function buildCompletedFilter(): CompletedRowFilter
    {
        return new CompletedRowFilter(
            connection: $this->completedFilterConnection,
            queue: $this->completedFilterQueue,
            from: $this->completedFilterFrom,
            to: $this->completedFilterTo,
        );
    }

    /**
     * Build the option lists shown in the filter dropdowns. Connection and
     * queue come from the configured snapshots (the package's source of
     * truth for what's tracked); class comes from the 24h class roster.
     *
     * @param  list<array<string, mixed>>  $classes
     * @return array{connections: list<string>, queues: list<string>, classes: list<string>}
     */
    private function buildFilterOptions(array $classes): array
    {
        $snapshots = array_values(array_filter(Config::array('snapshots'), is_array(...)));

        return [
            'connections' => $this->distinctStrings(array_column($snapshots, 'connection')),
            'queues' => $this->distinctStrings(array_column($snapshots, 'queue')),
            'classes' => $this->distinctStrings(array_column($classes, 'class')),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function distinctStrings(array $values): array
    {
        $out = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )));
        sort($out);

        return $out;
    }

    /**
     * Headline stats inspired by Horizon. All values are derived from data
     * already loaded for the dashboard — no extra round-trips to Redis.
     *
     * @param  list<array{timestamp: int, processed: int, failed: int}>  $throughput
     * @param  list<array<string, mixed>>  $queues
     * @param  list<array<string, mixed>>  $classes
     * @return array{jobs_per_minute: int, jobs_past_hour: int, failed_past_hour: int, max_throughput_hour: int, max_wait_ms: ?int, max_runtime_ms: ?int}
     */
    private function buildHeadlineStats(array $throughput, array $queues, array $classes): array
    {
        $latest = $throughput === [] ? ['processed' => 0, 'failed' => 0] : $throughput[count($throughput) - 1];
        $pastHour = $latest['processed'];

        $processedSeries = array_column($throughput, 'processed');

        return [
            'jobs_per_minute' => (int) round($pastHour / 60),
            'jobs_past_hour' => $pastHour,
            'failed_past_hour' => $latest['failed'],
            'max_throughput_hour' => $processedSeries === [] ? 0 : max($processedSeries),
            'max_wait_ms' => $this->maxIntCol($queues, 'wait_p95_ms'),
            'max_runtime_ms' => $this->maxIntCol($classes, 'p95_ms'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function maxIntCol(array $rows, string $key): ?int
    {
        $values = [];
        foreach ($rows as $row) {
            $v = $row[$key] ?? null;
            if (is_numeric($v)) {
                $values[] = (int) $v;
            }
        }

        return $values === [] ? null : max($values);
    }

    private function completedFiltersActive(): bool
    {
        return $this->selectedClass !== null
            || $this->completedFilterConnection !== ''
            || $this->completedFilterQueue !== ''
            || $this->completedFilterFrom !== ''
            || $this->completedFilterTo !== '';
    }

    private function buildFailedFilters(): FailedJobFilters
    {
        return new FailedJobFilters(
            connection: $this->filterConnection,
            queue: $this->filterQueue,
            class: $this->filterClass,
            from: $this->filterFrom,
            to: $this->filterTo,
        );
    }

    /**
     * Retry a single failed job. The host app must define the
     * `retryFailedJobs` Gate — this dashboard's `viewQueueInsights` Gate
     * is read-only and intentionally distinct from the write surface.
     *
     * Defence-in-depth ordering:
     *   1. Gate::authorize → 403 if denied (no Artisan call)
     *   2. RateLimiter (30 / minute / user) → flash banner if exhausted
     *   3. Artisan::call('queue:retry') wrapped in try/catch
     *
     * `queue:retry` is idempotent against an already-retried row, so a
     * concurrent operator retrying the same uuid is a safe no-op.
     */
    public function retryFailed(string $uuid): void
    {
        Gate::authorize('retryFailedJobs');

        if ($uuid === '') {
            return;
        }

        if (! $this->hitRetryRateLimit()) {
            Session::flash('qi.retry.error', 'Retry rate limit reached (30/min). Try again shortly.');

            return;
        }

        try {
            $exit = Artisan::call('queue:retry', ['id' => [$uuid]]);

            // Codex review: a non-zero exit code means queue:retry rejected
            // (row already retried, missing, driver-level failure). The
            // command does not throw — it returns the exit code. Treating
            // every non-throwing call as success would tell operators a
            // dead-letter row was requeued when it wasn't.
            if ($exit !== 0) {
                Log::warning('queue-insights.retry.exit_nonzero', [
                    'kind' => 'single',
                    'uuid' => $uuid,
                    'exit' => $exit,
                ]);
                Session::flash('qi.retry.error', 'Retry could not be dispatched (queue:retry returned non-zero — already retried, missing, or driver rejected).');

                return;
            }

            $this->logRetry('single', [$uuid]);
            $this->selectedFailedId = null;
            Session::flash('qi.retry.ok', 'Retry dispatched.');
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: retryFailed threw', [
                'uuid' => $uuid,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            Session::flash('qi.retry.error', 'Retry failed — check logs.');
        }
    }

    /**
     * Bulk-retry every failed job that matches the current filter set.
     *
     * Server-side safety contract (spec §3.2 / Resolved Q #5 + #7):
     *   - reject when *all* filters are empty (footgun guard)
     *   - reject when match count > 100 (no silent truncation)
     *   - dispatch the whole snapshot inside one Artisan call
     */
    public function retryFailedBulk(): void
    {
        Gate::authorize('retryFailedJobs');

        $filters = $this->buildFailedFilters();

        if ($filters->isEmpty()) {
            Session::flash('qi.retry.error', 'Bulk retry requires at least one filter.');

            return;
        }

        if (! $this->hitRetryRateLimit()) {
            Session::flash('qi.retry.error', 'Retry rate limit reached (30/min). Try again shortly.');

            return;
        }

        try {
            $uuids = $this->collectFilteredFailedUuids($filters);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: retryFailedBulk query threw', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            Session::flash('qi.retry.error', 'Bulk retry could not read failed_jobs.');

            return;
        }

        $count = count($uuids);

        if ($count === 0) {
            Session::flash('qi.retry.error', 'No failed jobs match the current filter.');

            return;
        }

        if ($count > 100) {
            Session::flash('qi.retry.error', sprintf(
                'Bulk retry rejected — %d matches exceed the 100 cap. Narrow the filter first.',
                $count,
            ));

            return;
        }

        try {
            $exit = Artisan::call('queue:retry', ['id' => $uuids]);

            if ($exit !== 0) {
                Log::warning('queue-insights.retry.exit_nonzero', [
                    'kind' => 'bulk',
                    'count' => $count,
                    'exit' => $exit,
                ]);
                Session::flash('qi.retry.error', sprintf(
                    'Bulk retry returned non-zero exit %d — some rows may have been already retried, missing, or rejected by the driver. Check logs.',
                    $exit,
                ));

                return;
            }

            $this->logRetry('bulk', $uuids);
            Session::flash('qi.retry.ok', sprintf('Retried %d job%s.', $count, $count === 1 ? '' : 's'));
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: retryFailedBulk threw', [
                'count' => $count,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            Session::flash('qi.retry.error', 'Bulk retry failed — check logs.');
        }
    }

    /**
     * Pluck uuids matching the current filters, capped at 101 rows so
     * the count check can distinguish "exactly 100" from "more than 100".
     *
     * @return list<string>
     */
    private function collectFilteredFailedUuids(FailedJobFilters $filters): array
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

    private function hitRetryRateLimit(): bool
    {
        $userId = Auth::id();
        $key = 'qi.retry:' . ($userId !== null ? (string) $userId : 'guest:' . request()->ip());

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    /**
     * @param  list<string>  $uuids
     */
    private function logRetry(string $kind, array $uuids): void
    {
        Log::info('queue-insights.retry', [
            'kind' => $kind,
            'uuids' => $uuids,
            'count' => count($uuids),
            'user_id' => Auth::id(),
            // Audit logs persist for a long time; the filter set is fully
            // user-controlled URL state, so unbounded logging is an info
            // leak (codex review). Sanitize each field: ASCII printable,
            // no control chars, max 80 chars.
            'filters' => [
                'connection' => $this->sanitizeAuditField($this->filterConnection),
                'queue' => $this->sanitizeAuditField($this->filterQueue),
                'class' => $this->sanitizeAuditField($this->filterClass),
                'from' => $this->sanitizeAuditField($this->filterFrom),
                'to' => $this->sanitizeAuditField($this->filterTo),
            ],
        ]);
    }

    private function sanitizeAuditField(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Replace anything outside the printable ASCII range with `?` so
        // attempts to smuggle log-injection control bytes (CR/LF/etc) get
        // neutralised before reaching the log driver.
        $clean = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return mb_substr($clean, 0, 80);
    }

    public function render(QueueInsights $svc): View
    {
        $captureMode = Config::string('capture.payloads', 'off');

        $queues = $this->buildQueueRows($svc);
        $classes = $this->buildClassRows($svc);

        $failedFilters = $this->buildFailedFilters();

        $recentCompleted = $svc->recentCompleted(50, $this->selectedClass);
        $recentFailed = $svc->recentFailed(50, $failedFilters);
        $throughput = $svc->hourlyThroughput();

        $selectedPayload = $this->resolveSelectedPayload($recentCompleted);
        $selectedFailed = $this->resolveSelectedFailed($recentFailed);

        // Decorate the selected rows with the per-job wait sample. Modals
        // render `Wait: —` when this is null (legacy job pre-dating the
        // JobQueued listener, or a driver that omits payload.uuid).
        if ($selectedPayload !== null) {
            $payloadUuid = $selectedPayload['uuid'] ?? null;
            $selectedPayload['wait_ms'] = is_string($payloadUuid)
                ? (string) ($svc->jobWaitMs($payloadUuid) ?? '')
                : '';

            // Reverse-lookup the batch id for THIS uuid only (one MGET) so
            // the details-modal's batch chip can render. The full
            // recentCompleted enrichment runs later for the table; doing it
            // again here for one row is the cheapest way to keep the modal
            // shape stable without depending on table-render order.
            if (is_string($payloadUuid) && $payloadUuid !== '') {
                $payloadBatchIds = BatchReader::batchIdsForUuids([$payloadUuid]);
                $selectedPayload['batch_id'] = $payloadBatchIds[$payloadUuid] ?? null;
            }
        }

        if ($selectedFailed !== null) {
            $failedUuid = $selectedFailed['uuid'] ?? null;
            $selectedFailed['wait_ms'] = is_string($failedUuid)
                ? $svc->jobWaitMs($failedUuid)
                : null;
        }

        // Bulk-retry UI eligibility (server-side enforcement still applies in
        // retryFailedBulk()). Only check when:
        //   - filters are non-empty (spec §3.4 footgun guard)
        //   - the host has defined the retryFailedJobs gate at all (otherwise
        //     the button has nowhere to land)
        $canRetry = Gate::has('retryFailedJobs') && Gate::allows('retryFailedJobs');
        $bulkRetryCount = null;
        if ($canRetry && ! $failedFilters->isEmpty()) {
            try {
                $bulkRetryCount = count($this->collectFilteredFailedUuids($failedFilters));
            } catch (Throwable) {
                $bulkRetryCount = null;
            }
        }

        $filterOptions = $this->buildFilterOptions($classes);

        $batches = BatchReader::sectionRows($svc, $this->expandedBatchId);

        $pendingEnabled = Config::bool('pending.enabled', true);
        $pendingRows = $pendingEnabled ? $svc->allPendingJobs(50) : [];
        $delayedRows = $pendingEnabled ? $svc->allDelayedJobs(50) : [];
        $inFlightRows = $pendingEnabled ? $svc->allInFlightJobs(50) : [];

        $selectedPending = $this->resolveSelectedPending(
            array_merge($inFlightRows, $pendingRows, $delayedRows),
            $svc,
        );

        $selectedBatch = $this->resolveSelectedBatch($batches);

        // Drive `inert` from the same booleans that actually mount the
        // modals — codex review #1. Routing it off raw selection ids
        // froze the dashboard when an id was set but the modal never
        // mounted (config flip, aged-out row, ?batch= URL with batches
        // disabled).
        $hasOpenModal = $selectedPayload !== null
            || $selectedFailed !== null
            || ($pendingEnabled && $this->selectedPendingUuid !== null)
            || (Config::bool('batches.enabled', true) && $this->expandedBatchId !== '');

        // Server-side pagination — slice the post-filter list to the active
        // page so the tab pane never renders more than PER_PAGE rows. Page
        // is clamped to the actual range so a bookmarked deep page on a list
        // that's since shrunk gracefully lands on the last available page.
        $completedAll = $this->buildCompletedFilter()->apply(RowEnricher::completed($recentCompleted));
        $completedTotal = count($completedAll);
        $completedTotalPages = max(1, (int) ceil($completedTotal / self::PER_PAGE));
        $completedPage = min(max(1, $this->completedPage), $completedTotalPages);
        $completedRowsPaged = array_slice($completedAll, ($completedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        $failedAll = RowEnricher::failed($recentFailed);
        $failedTotal = count($failedAll);
        $failedTotalPages = max(1, (int) ceil($failedTotal / self::PER_PAGE));
        $failedPage = min(max(1, $this->failedPage), $failedTotalPages);
        $failedRowsPaged = array_slice($failedAll, ($failedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        $aggregates = QueueAggregates::aggregate($queues);

        return ViewFactory::make('queue-insights::dashboard', [
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
            'completedFiltersActive' => $this->completedFiltersActive(),
            'failedRows' => $failedRowsPaged,
            'failedTotal' => $failedTotal,
            'failedPage' => $failedPage,
            'failedTotalPages' => $failedTotalPages,
            'failedPerPage' => self::PER_PAGE,
            'selectedClass' => $this->selectedClass,
            'selectedPayload' => $selectedPayload,
            'selectedFailed' => $selectedFailed,
            'payloadTab' => $this->payloadTab,
            'throughput' => $throughput,
            'stats' => $this->buildHeadlineStats($throughput, $queues, $classes),
            'pendingGapWarnThreshold' => Config::int('pending.gap_warn_threshold', 5),
            'failedFiltersActive' => ! $failedFilters->isEmpty(),
            'canRetry' => $canRetry,
            'bulkRetryCount' => $bulkRetryCount,
            'batches' => $batches,
            'batchesEnabled' => Config::bool('batches.enabled', true),
            'expandedBatchId' => $this->expandedBatchId,
            'selectedBatch' => $selectedBatch,
            'selectedPending' => $selectedPending,
            'selectedPendingUuid' => $this->selectedPendingUuid,
            'hasOpenModal' => $hasOpenModal,
        ]);
    }

    /**
     * Resolve the open batch row from the section data so the batch modal can
     * mount it. Searches the visible Batches section first (already loaded
     * for the page), then falls back to a direct `BatchReader::detailRow()`
     * lookup so a batch chip whose target sits OUTSIDE the recent-batches
     * window (`batches.max_per_query`) still resolves — without the fallback
     * the modal would land on the misleading "Batch no longer tracked"
     * empty state even though `Bus::findBatch()` succeeds.
     *
     * Returns null only when the BatchRepository row genuinely aged out.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return array<string, mixed>|null
     */
    private function resolveSelectedBatch(array $batches): ?array
    {
        if ($this->expandedBatchId === '') {
            return null;
        }

        foreach ($batches as $row) {
            if (($row['id'] ?? null) === $this->expandedBatchId) {
                return $row;
            }
        }

        return BatchReader::detailRow($this->expandedBatchId);
    }

    /**
     * Look up the currently-open pending row by uuid. Searches the rows we
     * already fetched for the section first, then falls back to a direct
     * `pending:{uuid}` hash lookup so a batched job sitting outside the top-50
     * aggregates (or any uuid arrived at via a deep-linked URL) still mounts
     * with real data — not the misleading "no longer pending" empty state
     * that comes from `null` here.
     *
     * Returns null only when the uuid genuinely isn't tracked anymore (worker
     * grabbed it mid-modal, TTL fired, or pending tracking was disabled at
     * queue time).
     *
     * @param  list<array<string, mixed>>  $allRows  pending + delayed + in-flight combined
     * @return array<string, mixed>|null
     */
    private function resolveSelectedPending(array $allRows, QueueInsights $svc): ?array
    {
        if ($this->selectedPendingUuid === null) {
            return null;
        }

        foreach ($allRows as $row) {
            if (($row['uuid'] ?? null) === $this->selectedPendingUuid) {
                return $row;
            }
        }

        return $svc->findPendingByUuid($this->selectedPendingUuid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildQueueRows(QueueInsights $svc): array
    {
        $rows = [];

        foreach ($svc->configuredQueues() as $entry) {
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

            $lastAt = $svc->lastSnapshotAt($connection, $canonical);
            $stale = ! $lastAt instanceof CarbonInterface || $lastAt->diffInSeconds(Date::now()) > 120;

            $driverRaw = config("queue.connections.{$connection}.driver", '—');

            $waitPercentiles = $svc->queueWaitPercentiles($connection, $canonical);

            $depth = $svc->liveDepth($connection, $canonical);
            $delayed = $svc->liveDelayed($connection, $canonical);

            $rows[] = $this->attachInspectorFields(
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'canonical' => $canonical,
                    'driver' => is_string($driverRaw) ? $driverRaw : '—',
                    'depth' => $depth,
                    'inflight' => $svc->liveInFlight($connection, $canonical),
                    'delayed' => $delayed,
                    'last_at' => $lastAt,
                    'stale' => $stale,
                    'error' => $svc->snapshotError($connection, $canonical),
                    'wait_p50_ms' => $waitPercentiles['p50'],
                    'wait_p95_ms' => $waitPercentiles['p95'],
                ],
                $svc,
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
    private function attachInspectorFields(array $row, QueueInsights $svc, string $connection, string $canonical, int $depth, ?int $delayed): array
    {
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
        $isOpen = $this->expandedQueueKey === $key;

        $tracked = $svc->pendingTrackedCount($connection, $canonical);
        $actual = $depth + ($delayed ?? 0);
        $gap = abs($tracked - $actual);

        return $row + [
            'inspector_key' => $key,
            'inspector_open' => $isOpen,
            'inspector_disabled' => false,
            'tracked_count' => $tracked,
            'pending_gap' => $gap,
            'pending_jobs' => $isOpen ? $svc->pendingJobs($connection, $canonical) : [],
            'delayed_jobs' => $isOpen ? $svc->delayedJobs($connection, $canonical) : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildClassRows(QueueInsights $svc): array
    {
        $rows = [];

        foreach ($svc->jobClasses() as $class) {
            $m = $svc->classMetrics($class);
            $rows[] = [
                'class' => $m->class,
                'processed_24h' => $m->processed24h,
                'failed_24h' => $m->failed24h,
                'avg_ms' => $m->avgDurationMs,
                'p95_ms' => $m->p95DurationMs,
                'max_ms' => $m->maxDurationMs,
                'last_run_at' => $m->lastRunAt,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $recentCompleted
     * @return array<string, string>|null
     */
    private function resolveSelectedPayload(array $recentCompleted): ?array
    {
        if ($this->selectedPayloadId === null) {
            return null;
        }

        foreach ($recentCompleted as $entry) {
            if (($entry['_id'] ?? null) === $this->selectedPayloadId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $recentFailed
     * @return array<array-key, mixed>|null
     */
    private function resolveSelectedFailed(array $recentFailed): ?array
    {
        if ($this->selectedFailedId === null) {
            return null;
        }

        foreach ($recentFailed as $row) {
            if (is_numeric($row['id'] ?? null) && (int) $row['id'] === $this->selectedFailedId) {
                return $row;
            }
        }

        return null;
    }
}
