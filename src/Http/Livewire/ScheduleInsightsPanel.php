<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use SanderMuller\QueueInsights\Scheduler\AggregatesQuery;
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

    #[Url(as: 's_pp', except: 50)]
    public int $perPage = 50;

    #[Url(as: 's_p', except: 1)]
    public int $page = 1;

    public function mount(): void
    {
        // Two-step gate: host app may register `viewScheduleInsights`
        // (preferred — narrower than queue access), otherwise fall back
        // to the outer `viewQueueInsights` already used to enter this
        // dashboard. The latter is implicit (the parent's middleware
        // already authorized it).
        if (Gate::has('viewScheduleInsights') && ! Gate::allows('viewScheduleInsights', Auth::user())) {
            abort(403);
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
        if (in_array($name, ['taskFilter', 'statusFilter', 'hostFilter', 'from', 'to', 'perPage'], true)) {
            $this->page = 1;
        }
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
        // Single per-task stats walk feeds both the headline tiles and
        // the needs-attention/healthy split — previously each task ran
        // through `taskWindowStats` twice per render.
        $tasksWithStats = [];
        foreach ($tasks as $task) {
            $stats = $aggregates->taskWindowStats($task['task_key']);
            $counters = $reader->counters($task['task_key']);
            $tasksWithStats[] = $task + ['stats' => $stats, 'counters' => $counters];
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

        $perPage = max(10, min(200, $this->perPage));
        $page = max(1, $this->page);
        $snapshotAtMs = $reader->snapshotAtMs();

        return ViewFactory::make('queue-insights::livewire.schedule-insights-panel', [
            'enabled' => Config::bool('scheduler.enabled', false),
            'snapshotAtMs' => $snapshotAtMs,
            'snapshotStale' => $this->isSnapshotStale($snapshotAtMs),
            'headlineStats' => $aggregates->headlineStatsFromComputed($tasksWithStats),
            'sparkline' => $aggregates->throughputSparkline($tasks),
            'needsAttention' => $needsAttention,
            'healthy' => $healthy,
            'tasksAll' => $tasksWithStats,
            'recentRuns' => $reader->recentRuns($filters, $perPage, $page),
            'totalRuns' => $reader->countRuns($filters),
            'distinctHosts' => $reader->distinctHosts(),
            'taskFilter' => $this->taskFilter,
            'statusFilter' => $this->statusFilter,
            'hostFilter' => $this->hostFilter,
            'from' => $this->from,
            'to' => $this->to,
            'perPage' => $perPage,
            'page' => $page,
        ]);
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
