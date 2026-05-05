<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\FailedJobUuidCollector;
use SanderMuller\QueueInsights\Support\UuidResolver;
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

        if (! in_array($connection, ConfiguredConnections::all(), true)) {
            abort(404);
        }

        if (Gate::has('viewQueueInsightsConnection')) {
            Gate::authorize('viewQueueInsightsConnection', $connection);
        }

        $this->scopeConnection = $connection;
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
        // Closing the modal entirely (X / Esc) drops the chain
        // navigation history — opening a different row from the table
        // shouldn't inherit a stale "Back to ..." trail.
        $this->chainBackStack = [];
    }

    public function openFailed(int $id): void
    {
        $this->selectedFailedId = $id;
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

    /**
     * Resolve a uuid to whichever surface it currently lives on (completed
     * stream, failed_jobs row, or pending hash) and dispatch to the matching
     * `open*` action. Drives the chain-lineage `↰ From` click-through.
     *
     * Pushes the modal the user was in onto `chainBackStack` so the parent
     * modal can render a "Back" button that returns the user to the child
     * — mirrors the "Back to batch" pattern but generalised across the
     * three item modal types.
     *
     * Aged-out parents (no surface match) fall through to a flash banner
     * instead of silently navigating nowhere.
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
        $this->filterClass = '';
        $this->filterFrom = '';
        $this->filterTo = '';
        $this->includeSilenced = false;
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
        $this->completedFilterConnection = '';
        $this->completedFilterQueue = '';
        $this->completedFilterFrom = '';
        $this->completedFilterTo = '';
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

        return new FailedJobFilters(
            connection: $connection,
            queue: $this->filterQueue,
            class: $this->filterClass,
            from: $this->filterFrom,
            to: $this->filterTo,
            includeSilenced: $this->includeSilenced,
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
            // Multi-tenant accountability — when scope is active, every retry
            // log entry carries which connection the operator was scoped to.
            // Sanitised the same way the URL-controlled filter fields are.
            'scope_connection' => $this->sanitizeAuditField($this->scopeConnection ?? ''),
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

    public function render(DashboardData $data): View
    {
        return ViewFactory::make('queue-insights::dashboard', $data->build($this));
    }
}
