<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View as ViewFactory;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use SanderMuller\QueueInsights\Dashboard\AuditContext;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Dashboard\RetryAction;
use SanderMuller\QueueInsights\Dashboard\RetryActor;
use SanderMuller\QueueInsights\Dashboard\RetryOutcome;
use SanderMuller\QueueInsights\Dashboard\RetryStatus;
use SanderMuller\QueueInsights\Support\AuditFieldSanitizer;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\FailedJobUuidCollector;
use SanderMuller\QueueInsights\Support\QueueScopeKey;
use SanderMuller\QueueInsights\Support\SilencedJobs;
use SanderMuller\QueueInsights\Support\UuidResolver;
use Throwable;

#[Layout('queue-insights::layouts.app')]
final class QueueInsightsDashboard extends Component
{
    #[Url(as: 'ck')]
    public ?string $selectedClass = null;

    /**
     * Active queue scope, canonical shape `'{connection}:{queue}'`. Empty string
     * = unscoped. URL-shareable so an operator can paste the dashboard URL and
     * land on a peer's queue-scoped view. Set by clicking a queue row on the
     * Queues tab; cleared by the inline scope-strip's X button or
     * `clearSelectedQueue()`. Mirrors `$selectedClass` in spirit but applies
     * to the queue axis (connection + canonical queue name).
     */
    #[Url(as: 'qk', except: '')]
    public string $selectedQueue = '';

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

    #[Url(as: 'ffrom', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'fto', except: '')]
    public string $filterTo = '';

    /**
     * "Show silenced" toggle on the failed-pane filter form. Default false
     * mirrors the SQL filter's default — silenced classes are hidden until
     * the operator opts in. URL-shareable so a deep-linked debug session
     * (`?fs=1`) reveals the silenced rows.
     */
    #[Url(as: 'fs', except: false)]
    public bool $includeSilenced = false;

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

    /**
     * "Show silenced" toggle on the completed-pane filter form. Mirrors
     * the failed-pane `$includeSilenced` (URL `?fs=1`). Independent toggle
     * so an operator can dig into silenced failures without unmuting
     * silenced successes (or vice versa). URL key `?cs=1`.
     */
    #[Url(as: 'cs', except: false)]
    public bool $completedIncludeSilenced = false;

    /*
     * Pagination — completed + failed lists. URL-shareable (`cp`/`fp`) so a
     * deep-linked page survives refresh. Per-page is owned by
     * `Dashboard\DashboardData::PER_PAGE` (fixed at 25 to keep the tab
     * content above-fold-friendly). Page is clamped to the available
     * range at render time so bookmarking page 5 of a list that's since
     * shrunk to 2 pages still lands on page 2 instead of an empty view.
     */

    #[Url(as: 'cp', except: 1)]
    public int $completedPage = 1;

    #[Url(as: 'fp', except: 1)]
    public int $failedPage = 1;

    /*
     * Per-page count for the Completed + Failed lists. URL-shareable
     * (`cpp` / `fpp`) and validated against
     * `Dashboard\DashboardData::PER_PAGE_OPTIONS` so a hostile
     * `?cpp=999999` can't force the dashboard to render an unbounded
     * row count. Default is `Dashboard\DashboardData::PER_PAGE` (10);
     * the `except` value matches the default so URL stays clean
     * unless the operator picks a non-default page size.
     */

    #[Url(as: 'cpp', except: 10)]
    public int $completedPerPage = 10;

    #[Url(as: 'fpp', except: 10)]
    public int $failedPerPage = 10;

    /*
     * Silenced-tab pagination — silenced classes are typically the
     * spammiest job traffic (vendor pings, retry storms), so a fixed
     * one-page-per-axis cap was making the tab read like an empty
     * roster on busy systems. Same shape as completed/failed: page
     * + per-page URL-bound, default 10/page, snapped to the shared
     * `PER_PAGE_OPTIONS` whitelist on every request.
     */

    #[Url(as: 'sfp', except: 1)]
    public int $silencedFailedPage = 1;

    #[Url(as: 'scp', except: 1)]
    public int $silencedCompletedPage = 1;

    #[Url(as: 'sfpp', except: 10)]
    public int $silencedFailedPerPage = 10;

    #[Url(as: 'scpp', except: 10)]
    public int $silencedCompletedPerPage = 10;

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
     * Active connection scope (path segment from `/queue-insights/{connection}`).
     * Distinct from `$filterConnection` (failed-jobs filter): scope narrows the
     * entire dashboard surface, filter narrows within failed/completed panels.
     * Lives in the path, not the query string — Livewire's snapshot persists
     * the prop across `wire:poll` round-trips so no `#[Url]` mirror is needed.
     */
    public ?string $scopeConnection = null;

    private RetryAction $retryAction;

    /**
     * Belt-and-suspenders clamp for the per-page props on every request.
     * `#[Url]` re-hydrates props from the query string on every request,
     * but `updated()` only fires on `set()` / `wire:model` updates — so a
     * hostile `?cpp=999999` deep-link skips that hook. Clamping in `boot()`
     * (runs post-hydration, pre-render) keeps the dropdown in sync with
     * the slice DashboardData renders.
     */
    public function boot(RetryAction $retryAction): void
    {
        $this->retryAction = $retryAction;

        if (! in_array($this->completedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->completedPerPage = DashboardData::PER_PAGE;
        }

        if (! in_array($this->failedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->failedPerPage = DashboardData::PER_PAGE;
        }

        if (! in_array($this->silencedFailedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->silencedFailedPerPage = DashboardData::PER_PAGE;
        }

        if (! in_array($this->silencedCompletedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->silencedCompletedPerPage = DashboardData::PER_PAGE;
        }
    }

    /**
     * Defense-in-depth: enforce the `viewQueueInsights` Gate on component mount,
     * not just on the bundled route. A host app that embeds the component in a
     * publicly-reachable view would otherwise leak queue insights.
     *
     * When `$connection` is supplied (route param or embed), validate it
     * against the configured snapshot connections and 404 on mismatch.
     * Then, if the optional `viewQueueInsightsConnection` Gate exists, run
     * an authorize-or-403 check against the scoped connection.
     */
    public function mount(?string $connection = null): void
    {
        if (Gate::has('viewQueueInsights')) {
            Gate::authorize('viewQueueInsights');
        }

        if ($connection === null) {
            // Un-scoped route 403s when the per-connection gate denies any
            // monitored connection — otherwise a partially-restricted
            // operator would see denied tenants' data. ConnectionNavBuilder
            // drops the "All" tab in that case so the only path here is a
            // manual URL hit.
            if (Gate::has('viewQueueInsightsConnection')) {
                foreach (ConfiguredConnections::all() as $name) {
                    Gate::authorize('viewQueueInsightsConnection', $name);
                }
            }

            return;
        }

        // Canonicalise the incoming path segment so legacy bookmarks under
        // a pre-alias name (e.g. `/queue-insights/redis` before the operator
        // published `aliases.redis = 'redis-staging'`) resolve to the
        // canonical scope. ConfiguredConnections::all() already returns
        // canonical names, so the in_array check stays consistent.
        $connection = ConnectionAlias::canonical($connection);

        if (! in_array($connection, ConfiguredConnections::all(), true)) {
            abort(404);
        }

        if (Gate::has('viewQueueInsightsConnection')) {
            Gate::authorize('viewQueueInsightsConnection', $connection);
        }

        $this->scopeConnection = $connection;
    }

    /**
     * Toggle the global class scope. Clicking the already-selected class on
     * the Classes tab clears the scope so a single click is the inverse of
     * itself — mirrors the queue-row toggle behaviour. `null` always clears.
     */
    public function selectClass(?string $class = null): void
    {
        $this->selectedClass = ($class !== null && $this->selectedClass === $class) ? null : $class;
    }

    public function clearSelectedClass(): void
    {
        $this->selectedClass = null;
    }

    /**
     * Toggle the global queue scope. Stored as canonical `'{conn}:{queue}'`
     * so downstream filters can decompose without re-resolving the key.
     * Clicking the already-selected queue clears the scope (toggle). Resets
     * paginators on every transition.
     *
     * The queue is canonicalised via `CanonicalQueueKey::from()` so an SQS
     * URL ("https://sqs.../work") collapses to its slug ("work") — matching
     * the canonical key shape every downstream reader (pending zsets,
     * inspector_key, completed-stream rows) compares against. Without this,
     * picking an SQS-URL queue would store a raw URL that nothing matches.
     */
    public function selectQueue(string $connection, string $queue): void
    {
        if ($connection === '' || $queue === '') {
            return;
        }

        try {
            $canonicalQueue = CanonicalQueueKey::from($queue);
        } catch (InvalidArgumentException) {
            return;
        }

        $key = QueueScopeKey::compose($connection, $canonicalQueue);
        $this->selectedQueue = $this->selectedQueue === $key ? '' : $key;
        $this->failedPage = 1;
        $this->completedPage = 1;
    }

    public function clearSelectedQueue(): void
    {
        $this->selectedQueue = '';
        $this->failedPage = 1;
        $this->completedPage = 1;
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
        // Closing the modal entirely (X / Esc) drops the chain
        // navigation history — opening a different row from the table
        // shouldn't inherit a stale "Back to ..." trail.
        $this->chainBackStack = [];
    }

    public function openFailed(int $id): void
    {
        $this->selectedFailedId = $id;
        // Reset to the default Structured tab — the failed modal shares the
        // `payloadTab` state with the completed-jobs modal, so a user who
        // flipped to JSON there shouldn't land on JSON here.
        $this->payloadTab = 'raw';
    }

    public function closeFailed(): void
    {
        $this->selectedFailedId = null;
        $this->chainBackStack = [];
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
        $this->chainBackStack = [];
    }

    /**
     * Chain-navigation back stack. Each frame captures the modal the
     * user was viewing BEFORE clicking a `↰ From` parent link, so the
     * parent modal can render a "Back to {child class}" button that
     * pops the stack and re-opens the child.
     *
     * Frame shape: `{type: 'completed'|'failed'|'pending', id: int|string, class: ?string}`.
     * Capped at 20 frames as cycle protection — pathological self-
     * dispatching chains can't grow this unbounded.
     *
     * @var list<array{type: string, id: int|string, class: ?string}>
     */
    public array $chainBackStack = [];

    /** Schedule-panel listener — forwards to `openByUuid` for identical behaviour. */
    #[On('qi-open-job-by-uuid')]
    public function openJobByUuidFromSchedule(string $uuid): void
    {
        $this->openByUuid($uuid);
    }

    /**
     * Resolve a uuid to whichever surface it currently lives on (completed
     * stream, failed_jobs row, or pending hash) and open the matching modal.
     * Drives the chain-lineage `↰ From` click-through; pushes the current
     * modal onto `chainBackStack` so the parent modal renders a Back button.
     * Aged-out parents flash a banner instead of silently navigating.
     */
    public function openByUuid(string $uuid, ?string $fromClass = null): void
    {
        $target = UuidResolver::resolve($uuid);

        if ($target === null) {
            Session::flash('qi.retry.error', 'That job has aged out of retention — its modal is no longer available.');

            return;
        }

        // Capture the current modal (if any) onto the back stack BEFORE
        // resetting selection so the parent modal's `Back` button can
        // restore it. Skip when no modal is currently open (a programmatic
        // open from somewhere outside the modal chrome).
        $currentFrame = $this->captureCurrentModalFrame($fromClass);
        if ($currentFrame !== null) {
            $this->chainBackStack[] = $currentFrame;
            // Cycle / pathological-chain protection — cap depth so a
            // self-dispatching chain can't grow this unbounded.
            if (count($this->chainBackStack) > 20) {
                $this->chainBackStack = array_slice($this->chainBackStack, -20);
            }
        }

        // Reset every selection before re-opening so the previous modal
        // closes before the new one mounts.
        $this->selectedPayloadId = null;
        $this->selectedFailedId = null;
        $this->selectedPendingUuid = null;

        match ($target['type']) {
            'completed' => $this->openPayload((string) $target['id']),
            'failed' => $this->openFailed((int) $target['id']),
            'pending' => $this->openPending((string) $target['id']),
        };
    }

    /**
     * Pop the most recent frame off the chain back stack and re-open the
     * matching modal. No-op when the stack is empty.
     */
    public function chainBack(): void
    {
        if ($this->chainBackStack === []) {
            return;
        }

        $frame = array_pop($this->chainBackStack);

        $this->selectedPayloadId = null;
        $this->selectedFailedId = null;
        $this->selectedPendingUuid = null;

        match ($frame['type']) {
            'completed' => $this->openPayload((string) $frame['id']),
            'failed' => $this->openFailed((int) $frame['id']),
            'pending' => $this->openPending((string) $frame['id']),
            default => null,
        };
    }

    /**
     * Snapshot the currently-open modal as a back-stack frame, or null
     * when nothing is open. The class label is best-effort — it's only
     * used to render the "Back to {Class}" button text and gracefully
     * falls back to "Back" when null.
     *
     * @return array{type: string, id: int|string, class: ?string}|null
     */
    private function captureCurrentModalFrame(?string $fromClass): ?array
    {
        if ($this->selectedPayloadId !== null) {
            return ['type' => 'completed', 'id' => $this->selectedPayloadId, 'class' => $fromClass];
        }

        if ($this->selectedFailedId !== null) {
            return ['type' => 'failed', 'id' => $this->selectedFailedId, 'class' => $fromClass];
        }

        if ($this->selectedPendingUuid !== null) {
            return ['type' => 'pending', 'id' => $this->selectedPendingUuid, 'class' => $fromClass];
        }

        return null;
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
        $this->filterFrom = '';
        $this->filterTo = '';
        $this->includeSilenced = false;
        $this->selectedQueue = '';
        $this->selectedClass = null;
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

    public function gotoSilencedFailedPage(int $page): void
    {
        $this->silencedFailedPage = max(1, $page);
    }

    public function gotoSilencedCompletedPage(int $page): void
    {
        $this->silencedCompletedPage = max(1, $page);
    }

    /**
     * Reset pagination when a filter changes — bookmarked page numbers stop
     * making sense the moment the underlying set shifts. Caught for any
     * Livewire-tracked filter by name prefix instead of one hook per prop.
     *
     * Also clamps user-supplied per-page values (URL params, dropdown
     * picks) to the whitelist before resetting the corresponding page —
     * a hostile `?cpp=999999` would otherwise force the dashboard to
     * materialise an unbounded slice.
     */
    public function updated(string $name): void
    {
        if ($name === 'completedPerPage') {
            if (! in_array($this->completedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
                $this->completedPerPage = DashboardData::PER_PAGE;
            }

            $this->completedPage = 1;

            return;
        }

        if ($name === 'failedPerPage') {
            if (! in_array($this->failedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
                $this->failedPerPage = DashboardData::PER_PAGE;
            }

            $this->failedPage = 1;

            return;
        }

        if ($name === 'silencedFailedPerPage') {
            if (! in_array($this->silencedFailedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
                $this->silencedFailedPerPage = DashboardData::PER_PAGE;
            }

            $this->silencedFailedPage = 1;

            return;
        }

        if ($name === 'silencedCompletedPerPage') {
            if (! in_array($this->silencedCompletedPerPage, DashboardData::PER_PAGE_OPTIONS, true)) {
                $this->silencedCompletedPerPage = DashboardData::PER_PAGE;
            }

            $this->silencedCompletedPage = 1;

            return;
        }

        if (str_starts_with($name, 'completedFilter') || $name === 'selectedClass' || $name === 'completedIncludeSilenced') {
            $this->completedPage = 1;
        } elseif (str_starts_with($name, 'filter') || $name === 'includeSilenced') {
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
        $this->selectedQueue = '';
        $this->completedFilterConnection = '';
        $this->completedFilterQueue = '';
        $this->completedFilterFrom = '';
        $this->completedFilterTo = '';
        $this->completedIncludeSilenced = false;
        $this->completedPage = 1;
    }

    /**
     * Build a `FailedJobFilters` value object from the current Livewire-tracked
     * filter state. Public so `Dashboard\DashboardData::build()` and the
     * package's own test suite can read the same filter shape the bulk-retry
     * action uses — keeps the two paths in lockstep without duplicating the
     * constructor wiring. Not part of the package's downstream API contract;
     * downstream hosts should configure filters via the existing `?fc=`/`?fq=`
     * URL state, not by calling this method.
     */
    public function buildFailedFilters(): FailedJobFilters
    {
        // When scope is active the connection axis is hard-pinned to scope —
        // any user-supplied `?fc=` that disagrees is ignored so the failed
        // panel cannot reach rows outside the scoped connection.
        $connection = $this->scopeConnection ?? $this->filterConnection;
        $queue = $this->filterQueue;

        // Global queue-scope (`?qk={conn}:{queue}`) overrides per-pane filters
        // so a queue selected from the Queues tab persists across Failed +
        // Completed lists. Rejected outright when the path-level scope's
        // connection differs from the queue scope's — a forged `?qk=` can't
        // pull rows from a foreign connection into a path-scoped dashboard.
        $queueScope = QueueScopeKey::decompose($this->selectedQueue);
        if ($queueScope !== null
            && ($this->scopeConnection === null || $queueScope['connection'] === $this->scopeConnection)
        ) {
            $connection = $this->scopeConnection ?? $queueScope['connection'];
            $queue = $queueScope['queue'];
        }

        // Auto-reveal silenced rows when the active class scope IS a silenced
        // class. Without this the failed list reads as empty after clicking a
        // silenced row on the Classes tab — the per-class filter pulls only
        // that class, then the silenced-exclusion query drops it. Mirrors the
        // same auto-reveal in `Dashboard\DashboardData::buildCompletedFilter`.
        $includeSilenced = $this->includeSilenced
            || ($this->selectedClass !== null
                && resolve(SilencedJobs::class)->isSilenced($this->selectedClass));

        return new FailedJobFilters(
            connection: $connection,
            queue: $queue,
            class: $this->selectedClass ?? '',
            from: $this->filterFrom,
            to: $this->filterTo,
            includeSilenced: $includeSilenced,
        );
    }

    /**
     * Retry a single failed job. The host app must define the
     * `retryFailedJobs` Gate — this dashboard's `viewQueueInsights` Gate
     * is read-only and intentionally distinct from the write surface.
     *
     * Authorization stays at the Livewire boundary; rate-limit, dispatch,
     * exit-code branching and audit logging live on `RetryAction`.
     */
    public function retryFailed(string $uuid): void
    {
        Gate::authorize('retryFailedJobs');

        if ($uuid === '') {
            return;
        }

        $outcome = $this->retryAction->single($uuid, $this->retryActor(), $this->auditContext());

        if ($outcome->status === RetryStatus::Ok) {
            $this->selectedFailedId = null;
        }

        Session::flash(
            $outcome->status === RetryStatus::Ok ? 'qi.retry.ok' : 'qi.retry.error',
            $outcome->message,
        );
    }

    /**
     * Bulk-retry every failed job that matches the current filter set.
     *
     * Server-side safety contract (spec §3.2 / Resolved Q #5 + #7):
     *   - reject when *all* filters are empty (footgun guard)
     *   - rate-limit BEFORE the collector hits failed_jobs (anti-DoS)
     *   - reject when match count > 100 (no silent truncation)
     *   - dispatch the whole snapshot inside one Artisan call (action)
     */
    public function retryFailedBulk(): void
    {
        Gate::authorize('retryFailedJobs');

        $filters = $this->buildFailedFilters();

        if ($filters->isEmpty()) {
            Session::flash('qi.retry.error', 'Bulk retry requires at least one filter.');

            return;
        }

        $actor = $this->retryActor();

        if (($limited = $this->retryAction->consumeRateLimit($actor)) instanceof RetryOutcome) {
            Session::flash('qi.retry.error', $limited->message);

            return;
        }

        try {
            $uuids = FailedJobUuidCollector::collect($filters);
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

        $outcome = $this->retryAction->bulk($uuids, $actor, $this->auditContext());

        Session::flash(
            $outcome->status === RetryStatus::Ok ? 'qi.retry.ok' : 'qi.retry.error',
            $outcome->message,
        );
    }

    private function retryActor(): RetryActor
    {
        $userId = Auth::id();
        $key = 'qi.retry:' . ($userId !== null ? (string) $userId : 'guest:' . request()->ip());

        return new RetryActor($userId, $key);
    }

    private function auditContext(): AuditContext
    {
        return new AuditContext(
            userId: Auth::id(),
            scopeConnection: AuditFieldSanitizer::clean($this->scopeConnection ?? ''),
            filterConnection: AuditFieldSanitizer::clean($this->filterConnection),
            filterQueue: AuditFieldSanitizer::clean($this->filterQueue),
            filterClass: AuditFieldSanitizer::clean($this->selectedClass ?? ''),
            filterFrom: AuditFieldSanitizer::clean($this->filterFrom),
            filterTo: AuditFieldSanitizer::clean($this->filterTo),
        );
    }

    public function render(DashboardData $data): View
    {
        return ViewFactory::make('queue-insights::dashboard', $data->build($this));
    }
}
