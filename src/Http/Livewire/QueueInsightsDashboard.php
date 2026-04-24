<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFactory;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;

#[Layout('queue-insights::layouts.app')]
final class QueueInsightsDashboard extends Component
{
    public ?string $selectedClass = null;

    public ?string $selectedPayloadId = null;

    public string $historyMetric = 'depth';

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
    }

    public function closePayload(): void
    {
        $this->selectedPayloadId = null;
    }

    public function setHistoryMetric(string $metric): void
    {
        if (in_array($metric, ['depth', 'inflight', 'delayed'], true)) {
            $this->historyMetric = $metric;
        }
    }

    public function render(QueueInsights $svc): View
    {
        $captureMode = Config::string('capture.payloads', 'off');

        $queues = $this->buildQueueRows($svc);
        $classes = $this->buildClassRows($svc);

        $recentCompleted = $svc->recentCompleted(50, $this->selectedClass);
        $recentFailed = $svc->recentFailed(50);

        $selectedPayload = $this->resolveSelectedPayload($recentCompleted);

        return ViewFactory::make('queue-insights::dashboard', [
            'queues' => $queues,
            'classes' => $classes,
            'captureEnabled' => $captureMode !== 'off',
            'captureMode' => $captureMode,
            'recentCompleted' => $recentCompleted,
            'recentFailed' => $recentFailed,
            'selectedClass' => $this->selectedClass,
            'selectedPayload' => $selectedPayload,
            'historyMetric' => $this->historyMetric,
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

            $rows[] = [
                'connection' => $connection,
                'queue' => $queue,
                'canonical' => $canonical,
                'driver' => is_string($driverRaw) ? $driverRaw : '—',
                'depth' => $svc->liveDepth($connection, $canonical),
                'inflight' => $svc->liveInFlight($connection, $canonical),
                'delayed' => $svc->liveDelayed($connection, $canonical),
                'last_at' => $lastAt,
                'stale' => $stale,
                'error' => $svc->snapshotError($connection, $canonical),
            ];
        }

        return $rows;
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
}
