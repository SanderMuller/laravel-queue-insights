<?php

declare(strict_types=1);

namespace Workbench\App\Http\Livewire;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;
use SanderMuller\QueueInsights\Support\RowEnricher;

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

    public string $expandedBatchId = '';

    public ?string $selectedPendingUuid = null;

    /*
     * Pagination — completed + failed lists. Mirrors the production component
     * so the preview exercises the same view contract. Per-page locked at 25.
     */

    private const int PER_PAGE = 25;

    public int $completedPage = 1;

    public int $failedPage = 1;

    public function gotoCompletedPage(int $page): void
    {
        $this->completedPage = max(1, $page);
    }

    public function gotoFailedPage(int $page): void
    {
        $this->failedPage = max(1, $page);
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'completedFilter') || $name === 'selectedClass') {
            $this->completedPage = 1;
        } elseif (str_starts_with($name, 'filter')) {
            $this->failedPage = 1;
        }
    }

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

    public function toggleBatchInspector(string $id): void
    {
        $this->expandedBatchId = $this->expandedBatchId === $id ? '' : $id;
    }

    /**
     * Mirror of QueueInsightsDashboard::openBatch — opens the batch modal
     * from a chip click in any item modal/row, closing whichever item modal
     * was open so only the batch modal remains visible.
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

    public function closeBatch(): void
    {
        $this->expandedBatchId = '';
    }

    public function openPending(string $uuid): void
    {
        $this->selectedPendingUuid = $uuid;
    }

    public function closePending(): void
    {
        $this->selectedPendingUuid = null;
    }

    public function clearFailedFilters(): void
    {
        $this->reset(['filterConnection', 'filterQueue', 'filterClass', 'filterFrom', 'filterTo']);
        $this->failedPage = 1;
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
                $payload = [
                    'displayName' => $row['display_name'] ?? 'App\\Jobs\\Preview',
                    'maxTries' => $row['max_tries'] ?? 3,
                    'attempts' => $row['attempts'] ?? 1,
                ];

                // When this seeded row carries a chain, embed a real serialized
                // chained command in `data.command` so the failed-modal's
                // RowEnricher::chainFromPayload() picks it up and renders the
                // Chain section. The shape mirrors what Laravel produces from
                // `Bus::chain([...])->dispatch()`.
                if (is_array($row['chain'] ?? null)) {
                    $payload['data'] = [
                        'commandName' => $row['display_name'] ?? 'App\\Jobs\\Preview',
                        'command' => self::buildChainedCommand($row['chain']),
                    ];
                }

                return array_merge($row, [
                    'uuid' => 'preview-uuid-' . $this->selectedFailedId,
                    'payload' => json_encode($payload),
                    'exception' => "{$row['exception_class']}: {$row['exception_message']}\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                    'wait_ms' => 250,
                ]);
            }
        }

        return null;
    }

    /**
     * Build a Laravel-shaped serialized job command with a non-empty `chained`
     * array, so the failed-modal Chain section can decode it via the same
     * `SerializedCommandReader::extractChainContext` path the real flow uses.
     *
     * @param  array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string}  $chain
     */
    private static function buildChainedCommand(array $chain): string
    {
        $nextJob = new \stdClass();
        if ($chain['chain_connection'] !== null) {
            $nextJob->connection = $chain['chain_connection'];
        }
        if ($chain['chain_queue'] !== null) {
            $nextJob->queue = $chain['chain_queue'];
        }

        $nextSerialized = serialize($nextJob);

        // Replace the outer class name from `stdClass` to the next-job FQCN
        // we want to display. The `O:N:"<FQCN>":` header drives the regex
        // extraction in SerializedCommandReader.
        $nextWithFqcn = preg_replace(
            '/^O:\d+:"[^"]+":/',
            'O:' . strlen($chain['next_class']) . ':"' . $chain['next_class'] . '":',
            $nextSerialized,
            1,
        );

        // Pad the chained array to `remaining` length with placeholder filler
        // jobs so `count($chained)` lines up with the displayed remaining.
        $chainedEntries = [$nextWithFqcn];
        for ($i = 1; $i < $chain['remaining']; $i++) {
            $filler = 'App\\Jobs\\ChainStep' . ($i + 1);
            $chainedEntries[] = 'O:' . strlen($filler) . ':"' . $filler . '":0:{}';
        }

        $outer = new \stdClass();
        $outer->chained = $chainedEntries;
        if ($chain['chain_connection'] !== null) {
            $outer->chainConnection = $chain['chain_connection'];
        }
        if ($chain['chain_queue'] !== null) {
            $outer->chainQueue = $chain['chain_queue'];
        }

        return serialize($outer);
    }

    /**
     * @param  list<array<string, mixed>>  $completedRows
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function resolvePreviewSelectedPending(array $rows): ?array
    {
        if ($this->selectedPendingUuid === null) {
            return null;
        }

        foreach ($rows as $row) {
            if (($row['uuid'] ?? null) === $this->selectedPendingUuid) {
                return $row;
            }
        }

        return null;
    }

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
        $this->completedPage = 1;
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

    /**
     * Seed two batches — one in-progress with a few items, one finished —
     * so the Batches section is exercisable in the preview. Item rows wire
     * their click handlers to the same openPayload / openFailed actions
     * used by the rest of the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Mirrors `QueueInsightsDashboard::resolveSelectedBatch` — picks the row
     * whose id matches the open `expandedBatchId` so the batch modal mounts
     * with the matching seed payload.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return array<string, mixed>|null
     */
    private function resolvePreviewSelectedBatch(array $batches): ?array
    {
        if ($this->expandedBatchId === '') {
            return null;
        }

        foreach ($batches as $row) {
            if (($row['id'] ?? null) === $this->expandedBatchId) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function seedBatches(Carbon $now): array
    {
        $batchOneItems = [
            ['uuid' => 'preview-uuid-batch1-a', 'class' => 'App\\Jobs\\GenerateReport', 'status' => 'completed', 'stream_id' => '01HK0M2', 'failed_id' => null, 'timestamp' => $now->copy()->subMinutes(15)->getTimestamp()],
            ['uuid' => 'preview-uuid-batch1-b', 'class' => 'App\\Jobs\\GenerateReport', 'status' => 'completed', 'stream_id' => null, 'failed_id' => null, 'timestamp' => $now->copy()->subMinutes(13)->getTimestamp()],
            ['uuid' => 'preview-uuid-batch1-c', 'class' => 'App\\Jobs\\GenerateReport', 'status' => 'failed', 'stream_id' => null, 'failed_id' => 1, 'timestamp' => $now->copy()->subMinutes(8)->getTimestamp()],
            ['uuid' => 'preview-uuid-batch1-d', 'class' => 'App\\Jobs\\GenerateReport', 'status' => 'pending', 'stream_id' => null, 'failed_id' => null, 'timestamp' => $now->copy()->subMinutes(2)->getTimestamp()],
        ];

        $batchTwoItems = [
            ['uuid' => 'preview-uuid-batch2-a', 'class' => 'App\\Jobs\\BackfillStats', 'status' => 'completed', 'stream_id' => null, 'failed_id' => null, 'timestamp' => $now->copy()->subHours(2)->getTimestamp()],
            ['uuid' => 'preview-uuid-batch2-b', 'class' => 'App\\Jobs\\BackfillStats', 'status' => 'completed', 'stream_id' => null, 'failed_id' => null, 'timestamp' => $now->copy()->subMinutes(110)->getTimestamp()],
        ];

        $batches = [
            [
                'id' => 'preview-batch-001',
                'name' => 'Nightly report fan-out',
                'total_jobs' => 12,
                'pending_jobs' => 4,
                'processed_jobs' => 7,
                'failed_jobs' => 1,
                'progress' => 66,
                'allows_failures' => true,
                'created_at' => $now->copy()->subMinutes(20),
                'finished_at' => null,
                'cancelled_at' => null,
                'items_seed' => $batchOneItems,
            ],
            [
                'id' => 'preview-batch-002',
                'name' => null,
                'total_jobs' => 5,
                'pending_jobs' => 0,
                'processed_jobs' => 5,
                'failed_jobs' => 0,
                'progress' => 100,
                'allows_failures' => false,
                'created_at' => $now->copy()->subHours(2),
                'finished_at' => $now->copy()->subMinutes(95),
                'cancelled_at' => null,
                'items_seed' => $batchTwoItems,
            ],
        ];

        $rows = [];
        foreach ($batches as $batch) {
            $isOpen = $this->expandedBatchId !== '' && $this->expandedBatchId === $batch['id'];
            $items = $batch['items_seed'];
            unset($batch['items_seed']);

            $rows[] = $batch + [
                'is_open' => $isOpen,
                'items' => $isOpen ? $items : [],
            ];
        }

        return $rows;
    }

    /**
     * Seed pending jobs (available_at <= now) so the dashboard's
     * Pending-now group has rows to show. Mirrors the production shape from
     * `QueueInsights::allPendingJobs()`.
     *
     * @return list<array<string, mixed>>
     */
    private function seedPendingRows(Carbon $now): array
    {
        return [
            ['uuid' => 'pending-uuid-1', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'connection' => 'redis', 'queue' => 'default', 'queued_at' => $now->copy()->subSeconds(45)->getTimestamp(), 'available_at' => $now->copy()->subSeconds(45)->getTimestamp(), 'batch_id' => null],
            ['uuid' => 'pending-uuid-2', 'class' => 'App\\Jobs\\SyncStripeCustomer', 'connection' => 'redis', 'queue' => 'default', 'queued_at' => $now->copy()->subMinutes(2)->getTimestamp(), 'available_at' => $now->copy()->subMinutes(2)->getTimestamp(), 'batch_id' => null],
            ['uuid' => 'pending-uuid-3', 'class' => 'App\\Jobs\\GenerateReport', 'connection' => 'sqs', 'queue' => 'reports', 'queued_at' => $now->copy()->subMinutes(8)->getTimestamp(), 'available_at' => $now->copy()->subMinutes(8)->getTimestamp(), 'batch_id' => 'preview-batch-001'],
        ];
    }

    /**
     * Seed delayed jobs (available_at > now) for the dashboard's Delayed
     * sub-group. Same row shape as `seedPendingRows` — the partial branches
     * on an `isDelayed` flag passed by the section, not on the row itself.
     *
     * @return list<array<string, mixed>>
     */
    private function seedDelayedRows(Carbon $now): array
    {
        return [
            ['uuid' => 'delayed-uuid-1', 'class' => 'App\\Jobs\\SendReminder', 'connection' => 'redis', 'queue' => 'mail', 'queued_at' => $now->copy()->subMinute()->getTimestamp(), 'available_at' => $now->copy()->addMinutes(2)->getTimestamp(), 'batch_id' => null, 'state' => null, 'started_at' => null],
            ['uuid' => 'delayed-uuid-2', 'class' => 'App\\Jobs\\WeeklyDigest', 'connection' => 'redis', 'queue' => 'mail', 'queued_at' => $now->copy()->subHour()->getTimestamp(), 'available_at' => $now->copy()->addHours(1)->getTimestamp(), 'batch_id' => null, 'state' => null, 'started_at' => null],
        ];
    }

    /**
     * Seed in-flight jobs — a worker has picked them up (RecordJobProcessing
     * stamps `state=in_flight` + `started_at`). Mirrors the shape returned by
     * `QueueInsights::allInFlightJobs()` so the dashboard can demo the
     * In-flight sub-group + per-row "running" badge in local preview.
     *
     * @return list<array<string, mixed>>
     */
    private function seedInFlightRows(Carbon $now): array
    {
        return [
            ['uuid' => 'inflight-uuid-1', 'class' => 'App\\Jobs\\ProcessImport', 'connection' => 'redis', 'queue' => 'default', 'queued_at' => $now->copy()->subSeconds(45)->getTimestamp(), 'available_at' => $now->copy()->subSeconds(45)->getTimestamp(), 'batch_id' => null, 'state' => 'in_flight', 'started_at' => $now->copy()->subSeconds(20)->getTimestamp()],
            ['uuid' => 'inflight-uuid-2', 'class' => 'App\\Jobs\\GenerateInvoicePdf', 'connection' => 'redis', 'queue' => 'high', 'queued_at' => $now->copy()->subMinutes(3)->getTimestamp(), 'available_at' => $now->copy()->subMinutes(3)->getTimestamp(), 'batch_id' => null, 'state' => 'in_flight', 'started_at' => $now->copy()->subMinutes(2)->getTimestamp()],
        ];
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

        // Raw stream-entry shape — `chain` is a JSON-encoded list, mirroring
        // what `RecordJobProcessed::writeStreams()` writes in production. The
        // chip path runs each row through `RowEnricher::decodeChain()` below;
        // the modal path uses these raw rows directly so its blade decodes
        // the JSON string itself.
        $importJobs = [
            ['class' => 'App\\Jobs\\NotifyImportFinished', 'connection' => 'redis', 'queue' => 'mail'],
            ['class' => 'App\\Jobs\\IndexImportArtifacts', 'connection' => 'redis', 'queue' => 'default'],
        ];
        $stripeJobs = [
            ['class' => 'App\\Jobs\\AuditCustomerSync', 'connection' => 'redis', 'queue' => 'default'],
        ];

        $completedRows = [
            ['_id' => '01HK0M1', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'short_id' => '01HK0M1', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 342, 'attempts' => 1, 'processed_at' => $now->copy()->subSeconds(20)->toIso8601String()],
            ['_id' => '01HK0M2', 'class' => 'App\\Jobs\\GenerateReport', 'short_id' => '01HK0M2', 'connection' => 'sqs', 'queue' => 'reports', 'duration_ms' => 18420, 'attempts' => 1, 'processed_at' => $now->copy()->subMinute()->toIso8601String(), 'batch_id' => 'preview-batch-001'],
            ['_id' => '01HK0M3', 'class' => 'App\\Jobs\\ProcessImport', 'short_id' => '01HK0M3', 'connection' => 'redis', 'queue' => 'mail', 'duration_ms' => 1240, 'attempts' => 2, 'processed_at' => $now->copy()->subMinutes(2)->toIso8601String(), 'chain' => json_encode($importJobs)],
            ['_id' => '01HK0M4', 'class' => 'App\\Jobs\\SyncStripeCustomer', 'short_id' => '01HK0M4', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 520, 'attempts' => 1, 'processed_at' => $now->copy()->subMinutes(3)->toIso8601String(), 'chain' => json_encode($stripeJobs)],
            ['_id' => '01HK0M5', 'class' => 'App\\Jobs\\SendWelcomeEmail', 'short_id' => '01HK0M5', 'connection' => 'redis', 'queue' => 'default', 'duration_ms' => 295, 'attempts' => 1, 'processed_at' => $now->copy()->subMinutes(5)->toIso8601String()],
        ];

        // Pad with deterministic generated rows so the preview exercises
        // multi-page pagination (PER_PAGE = 25 → 3 pages on this seed).
        $completedClassPool = [
            'App\\Jobs\\SendWelcomeEmail',
            'App\\Jobs\\GenerateReport',
            'App\\Jobs\\ProcessImport',
            'App\\Jobs\\SyncStripeCustomer',
            'App\\Jobs\\IndexImportArtifacts',
            'App\\Jobs\\NotifyImportFinished',
            'App\\Jobs\\BackfillStats',
            'App\\Jobs\\WeeklyDigest',
            'App\\Jobs\\AuditCustomerSync',
            'App\\Jobs\\GenerateInvoicePdf',
        ];
        $completedQueuePool = [
            ['redis', 'default'],
            ['redis', 'mail'],
            ['redis', 'high'],
            ['sqs', 'reports'],
        ];
        for ($i = 6; $i <= 65; $i++) {
            $cls = $completedClassPool[$i % count($completedClassPool)];
            $q = $completedQueuePool[$i % count($completedQueuePool)];
            $completedRows[] = [
                '_id' => '01HK0M' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'class' => $cls,
                'short_id' => '01HK0M' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'connection' => $q[0],
                'queue' => $q[1],
                'duration_ms' => 200 + (($i * 37) % 5000),
                'attempts' => $i % 13 === 0 ? 2 : 1,
                'processed_at' => $now->copy()->subMinutes($i)->toIso8601String(),
            ];
        }

        $chainProcessImport = [
            'next_class' => 'App\\Jobs\\NotifyImportFinished',
            'remaining' => 3,
            'chain_connection' => 'redis',
            'chain_queue' => 'mail',
        ];

        $failedRows = [
            ['id' => 1, 'display_name' => 'App\\Jobs\\GenerateReport', 'exception_class' => 'RuntimeException', 'exception_message' => 'Database connection timeout', 'short_uuid' => 'a3f9c2', 'connection' => 'sqs', 'queue' => 'reports', 'failed_at' => $now->copy()->subMinutes(8)->toIso8601String(), 'attempts' => 3, 'max_tries' => 3, 'batch_id' => 'preview-batch-001'],
            ['id' => 2, 'display_name' => 'App\\Jobs\\SendWelcomeEmail', 'exception_class' => 'Swift_TransportException', 'exception_message' => 'SMTP server refused connection', 'short_uuid' => 'b7e221', 'connection' => 'redis', 'queue' => 'mail', 'failed_at' => $now->copy()->subMinutes(20)->toIso8601String(), 'attempts' => 2, 'max_tries' => 3],
            ['id' => 3, 'display_name' => 'App\\Jobs\\ProcessImport', 'exception_class' => 'InvalidArgumentException', 'exception_message' => 'Malformed CSV row 482', 'short_uuid' => 'c1d809', 'connection' => 'redis', 'queue' => 'mail', 'failed_at' => $now->copy()->subHour()->toIso8601String(), 'attempts' => 1, 'max_tries' => 1, 'chain' => $chainProcessImport],
            ['id' => null, 'display_name' => 'App\\Jobs\\LegacyOrphan', 'exception_class' => null, 'exception_message' => null, 'short_uuid' => null, 'connection' => 'redis', 'queue' => 'default', 'failed_at' => $now->copy()->subHours(3)->toIso8601String(), 'attempts' => null, 'max_tries' => null],
        ];

        // Pad failed list to ~45 rows (≈ 2 pages at PER_PAGE=25) so the
        // preview exercises pagination on the failed tab too.
        $failedExceptionPool = [
            ['RuntimeException', 'Database connection timeout'],
            ['Swift_TransportException', 'SMTP server refused connection'],
            ['InvalidArgumentException', 'Malformed payload'],
            ['ConnectException', 'External API unreachable'],
            ['QueryException', 'Deadlock detected on retry'],
            ['JsonException', 'Invalid JSON in job payload'],
            ['ModelNotFoundException', 'Source record vanished mid-job'],
        ];
        for ($i = 10; $i <= 50; $i++) {
            $cls = $completedClassPool[$i % count($completedClassPool)];
            $exc = $failedExceptionPool[$i % count($failedExceptionPool)];
            $q = $completedQueuePool[$i % count($completedQueuePool)];
            $failedRows[] = [
                'id' => $i,
                'display_name' => $cls,
                'exception_class' => $exc[0],
                'exception_message' => $exc[1],
                'short_uuid' => substr(md5((string) $i), 0, 6),
                'connection' => $q[0],
                'queue' => $q[1],
                'failed_at' => $now->copy()->subMinutes($i * 2 + 5)->toIso8601String(),
                'attempts' => 1 + ($i % 3),
                'max_tries' => 3,
            ];
        }

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

        // Decode the chain field from each raw stream-entry row so the chip
        // partial sees `chain` as an array (matching what `RowEnricher::completed`
        // produces in production). The modal still receives the raw row via
        // `resolvePreviewSelectedPayload` so its blade decodes the JSON itself.
        $enrichedCompletedRows = array_map(static function (array $row): array {
            $chainEncoded = is_string($row['chain'] ?? null) ? $row['chain'] : '';
            $row['chain'] = RowEnricher::decodeChain($chainEncoded);

            return $row;
        }, $completedRows);

        // Slice for the active page so the preview matches production's
        // contract — the view receives a page-sized slice plus metadata for
        // the controls. Modal resolution uses the full unsliced lists above.
        $completedTotal = count($enrichedCompletedRows);
        $completedTotalPages = max(1, (int) ceil($completedTotal / self::PER_PAGE));
        $completedPage = min(max(1, $this->completedPage), $completedTotalPages);
        $enrichedCompletedRowsPaged = array_slice($enrichedCompletedRows, ($completedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        $failedTotal = count($failedRows);
        $failedTotalPages = max(1, (int) ceil($failedTotal / self::PER_PAGE));
        $failedPage = min(max(1, $this->failedPage), $failedTotalPages);
        $failedRowsPaged = array_slice($failedRows, ($failedPage - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'queues' => $queues,
            'classes' => $classes,
            'filterConnectionOptions' => $connections,
            'filterQueueOptions' => $queueNames,
            'filterClassOptions' => $classNames,
            'captureMode' => 'eager',
            'completedRows' => $enrichedCompletedRowsPaged,
            'completedTotal' => $completedTotal,
            'completedPage' => $completedPage,
            'completedTotalPages' => $completedTotalPages,
            'completedPerPage' => self::PER_PAGE,
            'failedRows' => $failedRowsPaged,
            'failedTotal' => $failedTotal,
            'failedPage' => $failedPage,
            'failedTotalPages' => $failedTotalPages,
            'failedPerPage' => self::PER_PAGE,
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
            'batches' => $this->seedBatches($now),
            'batchesEnabled' => true,
            'expandedBatchId' => $this->expandedBatchId,
            'selectedBatch' => $this->resolvePreviewSelectedBatch($this->seedBatches($now)),
            'hasOpenModal' => $this->selectedPayloadId !== null
                || $this->selectedFailedId !== null
                || $this->selectedPendingUuid !== null
                || $this->expandedBatchId !== '',
            // Pending-jobs section uses the same empty-state seeding strategy
            // — the real component fans this out across configured queues.
            'pendingRows' => $this->seedPendingRows($now),
            'selectedPendingUuid' => $this->selectedPendingUuid,
            'selectedPending' => $this->resolvePreviewSelectedPending(
                array_merge($this->seedInFlightRows($now), $this->seedPendingRows($now), $this->seedDelayedRows($now)),
            ),
            'delayedRows' => $this->seedDelayedRows($now),
            'inFlightRows' => $this->seedInFlightRows($now),
            'pendingEnabled' => true,
        ];
    }
}
