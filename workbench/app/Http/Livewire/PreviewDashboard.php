<?php

declare(strict_types=1);

namespace Workbench\App\Http\Livewire;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('queue-insights::layouts.app')]
final class PreviewDashboard extends Component
{
    public ?string $selectedClass = null;

    public ?string $selectedPayloadId = null;

    public ?int $selectedFailedId = null;

    public string $payloadTab = 'raw';

    public string $filterConnection = '';

    public string $filterQueue = '';

    public string $filterClass = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $completedFilterConnection = '';

    public string $completedFilterQueue = '';

    public string $completedFilterFrom = '';

    public string $completedFilterTo = '';

    public string $expandedQueueKey = '';

    public function openPayload(string $id): void
    {
        $this->selectedPayloadId = $id;
    }

    public function openFailed(int $id): void
    {
        $this->selectedFailedId = $id;
    }

    public function closePayload(): void
    {
        $this->selectedPayloadId = null;
    }

    public function closeFailed(): void
    {
        $this->selectedFailedId = null;
    }

    /**
     * Preview-only stub. Mirrors the real component's behaviour: close the
     * failed-job modal + flash a success banner. No queue:retry call, no
     * audit log — this is the workbench dashboard with seeded data, no real
     * jobs to redispatch.
     */
    public function retryFailed(string $uuid): void
    {
        $this->selectedFailedId = null;
        session()->flash('qi.retry.ok', "Retry dispatched (preview — uuid {$uuid}).");
    }

    public function retryFailedBulk(): void
    {
        session()->flash('qi.retry.ok', 'Retried N jobs (preview — no real dispatch).');
    }

    public function toggleQueueInspector(string $key): void
    {
        $this->expandedQueueKey = $this->expandedQueueKey === $key ? '' : $key;
    }

    public function clearFailedFilters(): void
    {
        $this->reset(['filterConnection', 'filterQueue', 'filterClass', 'filterFrom', 'filterTo']);
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function seedInspectorFields(array $q, Carbon $now): array
    {
        $key = "{$q['connection']}:{$q['queue']}";
        $isOpen = $this->expandedQueueKey === $key;
        $seed = $q['pending_seed'] ?? 'empty';
        unset($q['pending_seed']);

        $pending = [];
        $delayed = [];
        $tracked = 0;
        $gap = 0;

        if ($seed === 'small') {
            $pending = [
                ['uuid' => 'p1', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'queued_at' => $now->copy()->subSeconds(4)->getTimestamp(), 'available_at' => $now->copy()->subSeconds(4)->getTimestamp()],
                ['uuid' => 'p2', 'class' => 'App\\Jobs\\SyncStripeCustomer', 'queued_at' => $now->copy()->subSeconds(18)->getTimestamp(), 'available_at' => $now->copy()->subSeconds(18)->getTimestamp()],
            ];
            $tracked = count($pending);
        } elseif ($seed === 'mixed') {
            $pending = [
                ['uuid' => 'm1', 'class' => 'App\\Jobs\\ProcessImport', 'queued_at' => $now->copy()->subMinutes(2)->getTimestamp(), 'available_at' => $now->copy()->subMinutes(2)->getTimestamp()],
            ];
            $delayed = [
                ['uuid' => 'm2', 'class' => 'App\\Jobs\\SendReminder', 'queued_at' => $now->copy()->subMinute()->getTimestamp(), 'available_at' => $now->copy()->addMinutes(2)->getTimestamp()],
                ['uuid' => 'm3', 'class' => 'App\\Jobs\\WeeklyDigest', 'queued_at' => $now->copy()->subHour()->getTimestamp(), 'available_at' => $now->copy()->addHours(1)->getTimestamp()],
            ];
            $tracked = count($pending) + count($delayed);
        } elseif ($seed === 'gap') {
            // SQS-like: snapshot says depth=2480, our tracking only saw 3 jobs
            // queued through Laravel's event flow → tracking gap.
            $pending = [
                ['uuid' => 'g1', 'class' => 'App\\Jobs\\GenerateReport', 'queued_at' => $now->copy()->subMinutes(8)->getTimestamp(), 'available_at' => $now->copy()->subMinutes(8)->getTimestamp()],
            ];
            $tracked = 3;
            $gap = 2477;
        }

        return $q + [
            'inspector_key' => $key,
            'inspector_open' => $isOpen,
            'inspector_disabled' => false,
            'tracked_count' => $tracked,
            'pending_gap' => $gap,
            'pending_jobs' => $isOpen ? $pending : [],
            'delayed_jobs' => $isOpen ? $delayed : [],
        ];
    }

    /**
     * Hydrate the failed-modal payload from the seeded list when a row is
     * "open" via $selectedFailedId. Mirrors the real component's
     * resolveSelectedFailed() behaviour but reads from in-memory seeds.
     *
     * @param  list<array<string, mixed>>  $failedRows
     * @return array<string, mixed>|null
     */
    private function resolvePreviewSelectedFailed(array $failedRows): ?array
    {
        if ($this->selectedFailedId === null) {
            return null;
        }

        foreach ($failedRows as $row) {
            if (($row['id'] ?? null) === $this->selectedFailedId) {
                return array_merge($row, [
                    'uuid' => 'preview-uuid-' . $this->selectedFailedId,
                    'payload' => json_encode([
                        'displayName' => $row['display_name'] ?? 'App\\Jobs\\Preview',
                        'maxTries' => $row['max_tries'] ?? 3,
                        'attempts' => $row['attempts'] ?? 1,
                    ]),
                    'exception' => "{$row['exception_class']}: {$row['exception_message']}\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                    'wait_ms' => 250,
                ]);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $completedRows
     * @return array<string, mixed>|null
     */
    private function resolvePreviewSelectedPayload(array $completedRows): ?array
    {
        if ($this->selectedPayloadId === null) {
            return null;
        }

        foreach ($completedRows as $row) {
            if (($row['_id'] ?? null) === $this->selectedPayloadId) {
                return array_merge($row, ['uuid' => 'preview-uuid-' . $row['_id'], 'wait_ms' => '180']);
            }
        }

        return null;
    }

    /**
     * @param  list<array{timestamp: int, processed: int, failed: int}>  $throughput
     * @param  list<array<string, mixed>>  $queues
     * @param  list<array<string, mixed>>  $classes
     * @return array<string, int|null>
     */
    private function seedStats(array $throughput, array $queues, array $classes): array
    {
        $latest = $throughput === [] ? ['processed' => 0, 'failed' => 0] : $throughput[count($throughput) - 1];
        $waits = array_filter(array_column($queues, 'wait_p95_ms'), static fn ($v): bool => is_numeric($v));
        $runtimes = array_filter(array_column($classes, 'p95_ms'), static fn ($v): bool => is_numeric($v));

        return [
            'jobs_per_minute' => (int) round((int) ($latest['processed'] ?? 0) / 60),
            'jobs_past_hour' => (int) ($latest['processed'] ?? 0),
            'failed_past_hour' => (int) ($latest['failed'] ?? 0),
            'max_throughput_hour' => max(array_column($throughput, 'processed') ?: [0]),
            'max_wait_ms' => $waits === [] ? null : (int) max($waits),
            'max_runtime_ms' => $runtimes === [] ? null : (int) max($runtimes),
        ];
    }

    public function clearCompletedFilters(): void
    {
        $this->selectedClass = null;
        $this->reset(['completedFilterConnection', 'completedFilterQueue', 'completedFilterFrom', 'completedFilterTo']);
    }

    public function clearSelectedClass(): void
    {
        $this->selectedClass = null;
    }

    public function selectClass(string $class): void
    {
        $this->selectedClass = $class === $this->selectedClass ? null : $class;
    }

    public function render(): View
    {
        return ViewFactory::make('queue-insights::dashboard', $this->seedData());
    }

    /** @return array<string, mixed> */
    private function seedData(): array
    {
        $now = Carbon::now();

        $queueDefs = [
            ['connection' => 'redis', 'queue' => 'default', 'driver' => 'redis', 'depth' => 12, 'inflight' => 3, 'delayed' => 0, 'wait_p50_ms' => 42, 'wait_p95_ms' => 180, 'error' => null, 'stale' => false, 'last_at' => $now->copy()->subSeconds(8), 'pending_seed' => 'small'],
            ['connection' => 'redis', 'queue' => 'high', 'driver' => 'redis', 'depth' => 0, 'inflight' => 1, 'delayed' => 0, 'wait_p50_ms' => 15, 'wait_p95_ms' => 60, 'error' => null, 'stale' => false, 'last_at' => $now->copy()->subSeconds(2), 'pending_seed' => 'empty'],
            ['connection' => 'redis', 'queue' => 'mail', 'driver' => 'redis', 'depth' => 450, 'inflight' => 5, 'delayed' => 120, 'wait_p50_ms' => 1200, 'wait_p95_ms' => 3400, 'error' => null, 'stale' => false, 'last_at' => $now->copy()->subSeconds(15), 'pending_seed' => 'mixed'],
            ['connection' => 'sqs', 'queue' => 'reports', 'driver' => 'sqs', 'depth' => 2480, 'inflight' => 8, 'delayed' => 0, 'wait_p50_ms' => 5400, 'wait_p95_ms' => 22000, 'error' => null, 'stale' => false, 'last_at' => $now->copy()->subMinute(), 'pending_seed' => 'gap'],
            ['connection' => 'redis', 'queue' => 'webhooks', 'driver' => 'redis', 'depth' => 3, 'inflight' => 0, 'delayed' => 0, 'wait_p50_ms' => null, 'wait_p95_ms' => null, 'error' => null, 'stale' => true, 'last_at' => $now->copy()->subMinutes(7), 'pending_seed' => 'empty'],
            ['connection' => 'sqs', 'queue' => 'imports', 'driver' => 'sqs', 'depth' => '—', 'inflight' => '—', 'delayed' => '—', 'wait_p50_ms' => null, 'wait_p95_ms' => null, 'error' => 'AccessDenied: queue not found', 'stale' => false, 'last_at' => $now->copy()->subMinutes(2), 'pending_seed' => 'empty'],
        ];

        $queues = array_map(fn (array $q): array => $this->seedInspectorFields($q, $now), $queueDefs);

        $throughput = [];
        for ($i = 23; $i >= 0; $i--) {
            $throughput[] = [
                'timestamp' => $now->copy()->subHours($i)->getTimestamp(),
                'processed' => 200 + ((23 - $i) * 18) + ($i % 5) * 35,
                'failed' => $i % 7 === 0 ? 12 : ($i % 3),
            ];
        }

        $completedRows = [
            ['_id' => '01HK0M1', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'short_id' => '01HK0M1', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 342, 'attempts' => 1, 'processed_at' => $now->copy()->subSeconds(20)->toIso8601String()],
            ['_id' => '01HK0M2', 'class' => 'App\\Jobs\\GenerateReport', 'short_id' => '01HK0M2', 'connection' => 'sqs', 'queue' => 'reports', 'duration_ms' => 18420, 'attempts' => 1, 'processed_at' => $now->copy()->subMinute()->toIso8601String()],
            ['_id' => '01HK0M3', 'class' => 'App\\Jobs\\ProcessImport', 'short_id' => '01HK0M3', 'connection' => 'redis', 'queue' => 'mail', 'duration_ms' => 1240, 'attempts' => 2, 'processed_at' => $now->copy()->subMinutes(2)->toIso8601String()],
            ['_id' => '01HK0M4', 'class' => 'App\\Jobs\\SyncStripeCustomer', 'short_id' => '01HK0M4', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 520, 'attempts' => 1, 'processed_at' => $now->copy()->subMinutes(3)->toIso8601String()],
            ['_id' => '01HK0M5', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'short_id' => '01HK0M5', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 295, 'attempts' => 1, 'processed_at' => $now->copy()->subMinutes(5)->toIso8601String()],
        ];

        $failedRows = [
            ['id' => 1, 'display_name' => 'App\\Jobs\\GenerateReport', 'exception_class' => 'RuntimeException', 'exception_message' => 'Database connection timeout', 'short_uuid' => 'a3f9c2', 'connection' => 'sqs', 'queue' => 'reports', 'failed_at' => $now->copy()->subMinutes(8)->toIso8601String(), 'attempts' => 3, 'max_tries' => 3],
            ['id' => 2, 'display_name' => 'App\\Jobs\\SendWelcomeEmail', 'exception_class' => 'Swift_TransportException', 'exception_message' => 'SMTP server refused connection', 'short_uuid' => 'b7e221', 'connection' => 'redis', 'queue' => 'mail', 'failed_at' => $now->copy()->subMinutes(20)->toIso8601String(), 'attempts' => 2, 'max_tries' => 3],
            ['id' => 3, 'display_name' => 'App\\Jobs\\ProcessImport', 'exception_class' => 'InvalidArgumentException', 'exception_message' => 'Malformed CSV row 482', 'short_uuid' => 'c1d809', 'connection' => 'redis', 'queue' => 'mail', 'failed_at' => $now->copy()->subHour()->toIso8601String(), 'attempts' => 1, 'max_tries' => 1],
            ['id' => null, 'display_name' => 'App\\Jobs\\LegacyOrphan', 'exception_class' => null, 'exception_message' => null, 'short_uuid' => null, 'connection' => 'redis', 'queue' => 'default', 'failed_at' => $now->copy()->subHours(3)->toIso8601String(), 'attempts' => null, 'max_tries' => null],
        ];

        $classes = [
            ['class' => 'App\\Jobs\\SendWelcomeEmail', 'processed_24h' => 1842, 'failed_24h' => 4, 'avg_ms' => 312, 'p95_ms' => 820, 'max_ms' => 2400, 'last_run_at' => $now->copy()->subSeconds(20)],
            ['class' => 'App\\Jobs\\GenerateReport', 'processed_24h' => 120, 'failed_24h' => 8, 'avg_ms' => 15400, 'p95_ms' => 42000, 'max_ms' => 78000, 'last_run_at' => $now->copy()->subMinute()],
            ['class' => 'App\\Jobs\\ProcessImport', 'processed_24h' => 340, 'failed_24h' => 12, 'avg_ms' => 1180, 'p95_ms' => 4200, 'max_ms' => 9800, 'last_run_at' => $now->copy()->subMinutes(2)],
            ['class' => 'App\\Jobs\\SyncStripeCustomer', 'processed_24h' => 620, 'failed_24h' => 0, 'avg_ms' => 540, 'p95_ms' => 1320, 'max_ms' => 2800, 'last_run_at' => $now->copy()->subMinutes(3)],
        ];

        $connections = array_values(array_unique(array_column($queues, 'connection')));
        $queueNames = array_values(array_unique(array_column($queues, 'queue')));
        $classNames = array_values(array_unique(array_filter(array_column($classes, 'class'), is_string(...))));
        sort($connections);
        sort($queueNames);
        sort($classNames);

        return [
            'queues' => $queues,
            'classes' => $classes,
            'filterConnectionOptions' => $connections,
            'filterQueueOptions' => $queueNames,
            'filterClassOptions' => $classNames,
            'captureMode' => 'eager',
            'completedRows' => $completedRows,
            'failedRows' => $failedRows,
            'selectedClass' => $this->selectedClass,
            'selectedPayload' => $this->resolvePreviewSelectedPayload($completedRows),
            'selectedFailed' => $this->resolvePreviewSelectedFailed($failedRows),
            'payloadTab' => $this->payloadTab,
            'throughput' => $throughput,
            'stats' => $this->seedStats($throughput, $queues, $classes),
            'failedFiltersActive' => $this->filterConnection !== ''
                || $this->filterQueue !== ''
                || $this->filterClass !== ''
                || $this->filterFrom !== ''
                || $this->filterTo !== '',
            'pendingGapWarnThreshold' => 5,
            'completedFiltersActive' => $this->selectedClass !== null
                || $this->completedFilterConnection !== ''
                || $this->completedFilterQueue !== ''
                || $this->completedFilterFrom !== ''
                || $this->completedFilterTo !== '',
            // Preview-only: the real component reads $canRetry from
            // Gate::has('retryFailedJobs') && Gate::allows(...). We define the
            // gate permissively in WorkbenchServiceProvider so the UI is
            // demo-able locally; downstream apps still need to opt in.
            'canRetry' => true,
            // When filters are active, surface a non-null count so the
            // bulk-retry button materialises in preview. Real component
            // computes this from the filtered failed-jobs query.
            'bulkRetryCount' => ($this->filterConnection !== ''
                || $this->filterQueue !== ''
                || $this->filterClass !== ''
                || $this->filterFrom !== ''
                || $this->filterTo !== '') ? count($failedRows) : null,
        ];
    }
}
