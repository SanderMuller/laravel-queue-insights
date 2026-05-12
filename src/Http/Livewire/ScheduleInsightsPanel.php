<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use SanderMuller\QueueInsights\Dashboard\DashboardData;
use SanderMuller\QueueInsights\Scheduler\AggregatesQuery;
use SanderMuller\QueueInsights\Scheduler\CommandLabel;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

/**
 * Lazy-mounted schedule observability panel. Mounts inside the
 * existing dashboard's tab strip so the route + outer auth gate stay
 * one place — host apps that don't define `viewScheduleInsights`
 * fall back to allowing every user already past `viewQueueInsights`.
 *
 * Filter URL keys (`s_t` / `s_st` / …) are deliberately distinct from
 * the queue dashboard's `fc` / `fq` keys so a deep-linked schedule URL
 * can't accidentally narrow the queue panel after a tab switch.
 */
#[Lazy]
final class ScheduleInsightsPanel extends Component
{
    #[Url(as: 's_t', except: '')]
    public string $taskFilter = '';

    #[Url(as: 's_st', except: '')]
    public string $statusFilter = '';

    #[Url(as: 's_h', except: '')]
    public string $hostFilter = '';

    #[Url(as: 's_from', except: '')]
    public string $from = '';

    #[Url(as: 's_to', except: '')]
    public string $to = '';

    #[Url(as: 's_pp', except: 10)]
    public int $perPage = 10;

    #[Url(as: 's_p', except: 1)]
    public int $page = 1;

    /**
     * Selected task for the per-task drilldown modal. URL-bound so
     * deep-links round-trip; cleared by `closeTaskModal`.
     */
    #[Url(as: 's_tk', except: '')]
    public string $selectedTaskKey = '';

    /**
     * Selected scheduled run for the per-run drilldown modal. Composite
     * shape `{taskKey}:{runId}` — mirrors the `qi:sched:runs:all` zset
     * member format so a deep-linked operator URL is one HGET away from
     * resolved. Cleared by `closeRunModal`.
     */
    #[Url(as: 's_rid', except: '')]
    public string $selectedRunId = '';

    public function boot(): void
    {
        // URL-hydration of `?s_pp=...` bypasses `updated()`, so clamp on every
        // request. Mirrors `QueueInsightsDashboard::boot` for the queue
        // dashboard's per-page props.
        if (! in_array($this->perPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }
    }

    public function mount(ScheduleReader $reader): void
    {
        // Two-step gate: host app may register `viewScheduleInsights`
        // (preferred — narrower than queue access), otherwise fall back
        // to the outer `viewQueueInsights` already used to enter this
        // dashboard. The latter is implicit (the parent's middleware
        // already authorized it).
        if (Gate::has('viewScheduleInsights') && ! Gate::allows('viewScheduleInsights', Auth::user())) {
            abort(403);
        }

        // Deep-link slot validation — fail soft. A bookmarked run id
        // that has aged out clears the slot silently so the panel
        // renders without the modal. Spec rule #6.
        if ($this->selectedRunId !== '') {
            [$taskKey, $runId] = $this->splitRunSlot($this->selectedRunId);
            if ($taskKey === '' || $runId === '' || $reader->runDetail($taskKey, $runId) === null) {
                $this->selectedRunId = '';
            }
        }
    }

    public function placeholder(): View
    {
        return ViewFactory::make('queue-insights::livewire.schedule-insights-panel-placeholder');
    }

    public function clearFilters(): void
    {
        $this->taskFilter = '';
        $this->statusFilter = '';
        $this->hostFilter = '';
        $this->from = '';
        $this->to = '';
        $this->page = 1;
    }

    public function updated(string $name): void
    {
        if ($name === 'perPage' && ! in_array($this->perPage, DashboardData::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }

        if (in_array($name, ['taskFilter', 'statusFilter', 'hostFilter', 'from', 'to', 'perPage'], true)) {
            $this->page = 1;
        }
    }

    public function gotoRunsPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function openTaskModal(string $taskKey): void
    {
        if ($taskKey === '') {
            return;
        }

        $this->selectedTaskKey = $taskKey;
    }

    public function closeTaskModal(): void
    {
        $this->selectedTaskKey = '';
    }

    public function openRunModal(string $taskKey, string $runId): void
    {
        if ($taskKey === '' || $runId === '') {
            return;
        }

        $this->selectedRunId = $taskKey . ':' . $runId;
    }

    public function closeRunModal(): void
    {
        $this->selectedRunId = '';
    }

    /**
     * Click-through from a correlated-job uuid in the run modal.
     * Forwarded to the parent `QueueInsightsDashboard` via a Livewire
     * event — the parent owns the queue-side modal slots and runs the
     * uuid → surface resolution that drives `openByUuid`. Silenced
     * filters are NOT honoured here (CLAUDE.md silenced-jobs rule).
     */
    public function openJobByUuid(string $uuid): void
    {
        if ($uuid === '') {
            return;
        }

        // Close the run modal first so the parent's queue-side modal
        // doesn't stack on top of it.
        $this->closeRunModal();
        $this->dispatch('qi-open-job-by-uuid', uuid: $uuid);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRunSlot(string $slot): array
    {
        $parts = explode(':', $slot, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        return [$parts[0], $parts[1]];
    }

    public function render(ScheduleReader $reader, AggregatesQuery $aggregates): View
    {
        $filters = [
            'task' => $this->taskFilter !== '' ? $this->taskFilter : null,
            'status' => $this->statusFilter !== '' ? $this->statusFilter : null,
            'host' => $this->hostFilter !== '' ? $this->hostFilter : null,
            'from_ms' => $this->parseToMs($this->from),
            'to_ms' => $this->parseToMs($this->to),
        ];

        $tasks = $reader->tasks();
        $statsByKey = [];
        foreach ($aggregates->computeStatsForTasks($tasks) as $row) {
            $statsByKey[$row['task_key']] = $row['stats'];
        }

        $tasksWithStats = [];
        foreach ($tasks as $task) {
            $tasksWithStats[] = $task + [
                'stats' => $statsByKey[$task['task_key']] ?? AggregatesQuery::emptyStats(),
            ];
        }

        $needsAttention = [];
        $healthy = [];
        foreach ($tasksWithStats as $row) {
            $hasIssue = $row['stats']['failed'] > 0
                || $row['stats']['hung'] > 0
                || $row['stats']['missed'] > 0;
            if ($hasIssue) {
                $needsAttention[] = $row;
            } else {
                $healthy[] = $row;
            }
        }

        $perPage = $this->perPage;
        $snapshotAtMs = $reader->snapshotAtMs();

        $totalRuns = $reader->countRuns($filters);
        $totalPages = max(1, (int) ceil($totalRuns / $perPage));
        $page = min(max(1, $this->page), $totalPages);

        $recentRuns = $reader->recentRuns($filters, $perPage, $page);
        $runsPaginator = new LengthAwarePaginator(
            items: $recentRuns,
            total: $totalRuns,
            perPage: $perPage,
            currentPage: $page,
            options: ['pageName' => 's_p'],
        );

        $taskModal = $this->hydrateTaskModal($reader, $tasksWithStats);
        $runModal = $this->hydrateRunModal($reader, $tasksWithStats);

        return ViewFactory::make('queue-insights::livewire.schedule-insights-panel', [
            'enabled' => Config::bool('scheduler.enabled', false),
            'snapshotAtMs' => $snapshotAtMs,
            'snapshotStale' => $this->isSnapshotStale($snapshotAtMs),
            'headlineStats' => $aggregates->headlineStatsFromComputed($tasksWithStats),
            'sparkline' => $aggregates->throughputSparkline($tasks),
            'needsAttention' => $needsAttention,
            'healthy' => $healthy,
            'tasksAll' => $tasksWithStats,
            'recentRuns' => $recentRuns,
            'totalRuns' => $totalRuns,
            'runsPaginator' => $runsPaginator,
            'perPageOptions' => DashboardData::PER_PAGE_OPTIONS,
            'distinctHosts' => $reader->distinctHosts(),
            'taskFilter' => $this->taskFilter,
            'statusFilter' => $this->statusFilter,
            'hostFilter' => $this->hostFilter,
            'from' => $this->from,
            'to' => $this->to,
            'perPage' => $perPage,
            'page' => $page,
            'selectedTask' => $taskModal['task'],
            'selectedTaskHosts' => $taskModal['hosts'],
            'selectedTaskRuns' => $taskModal['runs'],
            'selectedRun' => $runModal['run'],
            'selectedRunOutput' => $runModal['output'],
            'selectedRunTaskLabel' => $runModal['label'],
            'selectedRunIsClosure' => $runModal['is_closure'],
        ]);
    }

    /**
     * Hydrate the per-task modal payload. Returns empty placeholders
     * when the slot is unset or the task isn't in the snapshot — the
     * modal is gated by `selectedTaskKey !== ''` in the view.
     *
     * @param  list<array<string, mixed>>  $tasksWithStats
     * @return array{task: ?array<string, mixed>, hosts: array<string, int>, runs: list<array<string, mixed>>}
     */
    private function hydrateTaskModal(ScheduleReader $reader, array $tasksWithStats): array
    {
        if ($this->selectedTaskKey === '') {
            return ['task' => null, 'hosts' => [], 'runs' => []];
        }

        $task = null;
        foreach ($tasksWithStats as $row) {
            if (($row['task_key'] ?? null) === $this->selectedTaskKey) {
                $task = $row;

                break;
            }
        }

        if ($task === null) {
            return ['task' => null, 'hosts' => [], 'runs' => []];
        }

        return [
            'task' => $task,
            'hosts' => $reader->hostDistribution($this->selectedTaskKey),
            'runs' => $reader->recentRuns(['task' => $this->selectedTaskKey], 50, 1),
        ];
    }

    /**
     * Hydrate the per-run modal payload. `run === null` drives the
     * "Expired" empty state in the modal partial.
     *
     * @param  list<array<string, mixed>>  $tasksWithStats
     * @return array{run: ?array<string, mixed>, output: ?string, label: ?string, is_closure: bool}
     */
    private function hydrateRunModal(ScheduleReader $reader, array $tasksWithStats): array
    {
        if ($this->selectedRunId === '') {
            return ['run' => null, 'output' => null, 'label' => null, 'is_closure' => false];
        }

        [$runTaskKey, $runId] = $this->splitRunSlot($this->selectedRunId);
        if ($runTaskKey === '' || $runId === '') {
            return ['run' => null, 'output' => null, 'label' => null, 'is_closure' => false];
        }

        $run = $reader->runDetail($runTaskKey, $runId);
        if ($run === null) {
            return ['run' => null, 'output' => null, 'label' => null, 'is_closure' => false];
        }

        [$label, $isClosure] = $this->resolveRunLabel($runTaskKey, $tasksWithStats);

        $output = null;
        if ($run['has_output'] && ! $isClosure) {
            $output = $reader->runOutput($runTaskKey, $runId);
        }

        return ['run' => $run, 'output' => $output, 'label' => $label, 'is_closure' => $isClosure];
    }

    /**
     * @param  list<array<string, mixed>>  $tasksWithStats
     * @return array{0: ?string, 1: bool}
     */
    private function resolveRunLabel(string $taskKey, array $tasksWithStats): array
    {
        foreach ($tasksWithStats as $row) {
            if (($row['task_key'] ?? null) !== $taskKey) {
                continue;
            }

            $label = is_string($row['description'] ?? null) && $row['description'] !== ''
                ? $row['description']
                : (is_string($row['command'] ?? null) ? CommandLabel::short($row['command']) : null);
            $isClosure = ($row['type'] ?? '') === 'closure';

            return [$label, $isClosure];
        }

        return [null, false];
    }

    private function parseToMs(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        try {
            return (int) (Date::parse($value)->getTimestamp() * 1000);
        } catch (Throwable) {
            return null;
        }
    }

    private function isSnapshotStale(?int $atMs): bool
    {
        if ($atMs === null) {
            return false;
        }

        return Date::now()->getTimestampMs() - $atMs > 3600000;
    }
}
