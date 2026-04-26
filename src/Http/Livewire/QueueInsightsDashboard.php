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
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use Throwable;

#[Layout('queue-insights::layouts.app')]
final class QueueInsightsDashboard extends Component
{
    #[Url(as: 'ck')]
    public ?string $selectedClass = null;

    public ?string $selectedPayloadId = null;

    public ?int $selectedFailedId = null;

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
     * Pending-jobs inspector — single-queue expand state. Format:
     * "{connection}:{canonical-queue}". Empty string = nothing expanded.
     * URL-shareable so an operator can paste the dashboard URL and land
     * on a peer's expanded inspector view.
     */

    #[Url(as: 'qopen', except: '')]
    public string $expandedQueueKey = '';

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
    }

    public function toggleQueueInspector(string $key): void
    {
        // Single-queue expand keeps render() costs bounded — only one set of
        // pendingJobs / delayedJobs round-trips per poll. Multi-open is an
        // operator request away if it ever lands on the roadmap.
        $this->expandedQueueKey = $this->expandedQueueKey === $key ? '' : $key;
    }

    public function clearCompletedFilters(): void
    {
        $this->selectedClass = null;
        $this->completedFilterConnection = '';
        $this->completedFilterQueue = '';
        $this->completedFilterFrom = '';
        $this->completedFilterTo = '';
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

        return ViewFactory::make('queue-insights::dashboard', [
            'queues' => $queues,
            'classes' => $classes,
            'filterConnectionOptions' => $filterOptions['connections'],
            'filterQueueOptions' => $filterOptions['queues'],
            'filterClassOptions' => $filterOptions['classes'],
            'captureMode' => $captureMode,
            'completedRows' => $this->buildCompletedFilter()->apply($this->enrichCompletedRows($recentCompleted)),
            'completedFiltersActive' => $this->completedFiltersActive(),
            'failedRows' => $this->enrichFailedRows($recentFailed),
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
        ]);
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
     * Enrich recentCompleted stream entries with a short id suffix for display.
     *
     * @param  list<array<string, string>>  $recentCompleted
     * @return list<array<string, mixed>>
     */
    private function enrichCompletedRows(array $recentCompleted): array
    {
        $rows = [];
        foreach ($recentCompleted as $row) {
            $id = $row['_id'] ?? '';
            $rows[] = $row + [
                'short_id' => is_string($id) && $id !== '' ? mb_substr(explode('-', $id)[0], -9) : '',
            ];
        }

        return $rows;
    }

    /**
     * Enrich failed_jobs rows with decoded-payload fields for a Horizon-style 2-line row:
     *   - display_name (class name from payload)
     *   - attempts + max_tries
     *   - exception_class + exception_message (first line parse)
     *   - short_uuid (last 8 chars — enough to scan)
     *
     * @param  list<array<array-key, mixed>>  $recentFailed
     * @return list<array<string, mixed>>
     */
    private function enrichFailedRows(array $recentFailed): array
    {
        $rows = [];
        foreach ($recentFailed as $row) {
            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $exception = is_string($row['exception'] ?? null) ? $row['exception'] : '';
            $exceptionFirst = explode("\n", $exception, 2)[0] ?? '';
            [$excClass, $excMessage] = $this->splitExceptionHeader($exceptionFirst);

            $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : '';

            $rows[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                'uuid' => $uuid,
                'short_uuid' => $uuid !== '' ? mb_substr($uuid, -8) : '',
                'connection' => $row['connection'] ?? null,
                'queue' => $row['queue'] ?? null,
                'failed_at' => $row['failed_at'] ?? null,
                'display_name' => is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
                    ? $payload['displayName']
                    : null,
                'attempts' => is_array($payload) && isset($payload['attempts']) && is_numeric($payload['attempts'])
                    ? (int) $payload['attempts']
                    : null,
                'max_tries' => is_array($payload) && isset($payload['maxTries']) && is_numeric($payload['maxTries'])
                    ? (int) $payload['maxTries']
                    : null,
                'exception_class' => $excClass,
                'exception_message' => $excMessage,
            ];
        }

        return $rows;
    }

    /**
     * "RuntimeException: Something broke" → ["RuntimeException", "Something broke"].
     *
     * @return array{0: string, 1: string}
     */
    private function splitExceptionHeader(string $firstLine): array
    {
        $colon = strpos($firstLine, ':');
        if ($colon === false) {
            return [$firstLine, ''];
        }

        return [
            trim(substr($firstLine, 0, $colon)),
            trim(substr($firstLine, $colon + 1)),
        ];
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
