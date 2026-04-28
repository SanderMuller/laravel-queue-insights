@props([
    /**
     * @var array<string, mixed>|null Batch row from `BatchReader::sectionRows()`
     * — null when the row was raced out from under the open modal.
     */
    'batch' => null,
])

@php
    $row = is_array($batch) ? $batch : null;
    $id = is_string($row['id'] ?? null) ? $row['id'] : '';
    $name = $row['name'] ?? null;
    $label = is_string($name) && $name !== '' ? $name : ($id !== '' ? 'Batch ' . substr($id, 0, 8) : 'Batch');

    $total = is_int($row['total_jobs'] ?? null) ? $row['total_jobs'] : 0;
    $pending = is_int($row['pending_jobs'] ?? null) ? $row['pending_jobs'] : 0;
    $failed = is_int($row['failed_jobs'] ?? null) ? $row['failed_jobs'] : 0;
    $processed = is_int($row['processed_jobs'] ?? null) ? $row['processed_jobs'] : 0;
    $progress = is_int($row['progress'] ?? null) ? $row['progress'] : 0;

    $cancelledAt = $row['cancelled_at'] ?? null;
    $finishedAt = $row['finished_at'] ?? null;
    $createdAt = $row['created_at'] ?? null;

    $isCancelled = $cancelledAt instanceof \Carbon\CarbonInterface;
    $isFinished = $finishedAt instanceof \Carbon\CarbonInterface;
    $hasFailures = $failed > 0;

    if ($isCancelled || ($hasFailures && ! ($row['allows_failures'] ?? false))) {
        $barTone = 'bg-red-500';
        $statusChip = ['label' => $isCancelled ? 'cancelled' : 'cancelled (first failure)', 'cls' => 'bg-red-50 text-red-700 ring-red-600/20'];
        $heroChrome = 'from-red-50 ring-red-600/10';
    } elseif ($isFinished && ! $hasFailures) {
        $barTone = 'bg-emerald-500';
        $statusChip = ['label' => 'finished', 'cls' => 'bg-gray-50 text-gray-700 ring-gray-950/10'];
        $heroChrome = 'from-emerald-50 ring-emerald-600/10';
    } elseif ($hasFailures) {
        $barTone = 'bg-amber-500';
        $statusChip = null;
        $heroChrome = 'from-amber-50 ring-amber-600/10';
    } else {
        $barTone = 'bg-emerald-500';
        $statusChip = null;
        $heroChrome = 'from-emerald-50 ring-emerald-600/10';
    }

    $items = is_array($row['items'] ?? null) ? $row['items'] : [];
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-batch-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closeBatch()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closeBatch">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 bg-white px-4 py-4">
            <div class="flex items-center gap-2">
                <span class="inline-flex size-6 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 ring-1 ring-inset ring-indigo-600/20">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
                    </svg>
                </span>
                <h3 id="qi-batch-modal-title" class="text-sm font-semibold text-gray-900">Batch</h3>
            </div>
            <button type="button"
                    wire:click="closeBatch"
                    aria-label="Close batch modal"
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                </svg>
            </button>
        </div>

        <div class="p-4">
            @if ($row === null)
                <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center">
                    <p class="text-sm font-medium text-gray-900">Batch no longer tracked</p>
                    <p class="mt-1 text-xs text-gray-500">
                        The BatchRepository row aged out, or our index TTL fired before the next poll. Close this modal — the Batches list refreshes on the next 10s tick.
                    </p>
                </div>
            @else
                {{-- Identity hero — name + id + progress bar + counts --}}
                <section class="mb-6">
                    <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">Batch</p>
                    <div class="rounded-xl bg-linear-to-br to-white p-4 ring-1 ring-inset {{ $heroChrome }}">
                        <dl>
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Name</dt>
                            <dd class="mt-1 break-all text-sm font-medium text-gray-900">{{ $label }}</dd>
                        </dl>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                            <dl class="inline-flex items-center divide-x divide-gray-950/10 overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                                <dt class="bg-gray-50 px-2 py-0.5 font-medium text-gray-500">ID</dt>
                                <dd class="bg-gray-50 px-2 py-0.5 font-mono text-gray-800">{{ $id }}</dd>
                            </dl>
                            @if ($statusChip)
                                <span class="rounded-md px-1.5 py-0.5 font-medium ring-1 ring-inset {{ $statusChip['cls'] }}">
                                    {{ $statusChip['label'] }}
                                </span>
                            @endif
                        </div>

                        <div class="mt-4">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-950/5">
                                <div class="h-full {{ $barTone }} transition-all" style="width: {{ max(2, $progress) }}%"></div>
                            </div>
                            <p class="mt-1.5 text-xs tabular-nums text-gray-500">{{ $progress }}% · {{ $processed }}/{{ $total }} processed</p>
                        </div>
                    </div>

                    {{-- Counts row --}}
                    <dl class="mt-3 grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5">
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Processed</dt>
                            <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-emerald-700">{{ $processed }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Failed</dt>
                            <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums {{ $hasFailures ? 'text-red-700' : 'text-gray-900' }}">{{ $failed }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Pending</dt>
                            <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900">{{ $pending }}</dd>
                        </div>
                    </dl>

                    {{-- Timeline --}}
                    @if ($createdAt instanceof \Carbon\CarbonInterface || $finishedAt instanceof \Carbon\CarbonInterface)
                        <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-950/5 pt-3 text-xs text-gray-500">
                            @if ($createdAt instanceof \Carbon\CarbonInterface)
                                <div class="flex items-baseline gap-1.5">
                                    <dt class="text-gray-400">created</dt>
                                    <dd title="{{ $createdAt->toIso8601String() }}">{{ $createdAt->diffForHumans() }}</dd>
                                </div>
                            @endif
                            @if ($finishedAt instanceof \Carbon\CarbonInterface)
                                <div class="flex items-baseline gap-1.5">
                                    <dt class="text-gray-400">{{ $isCancelled ? 'cancelled' : 'finished' }}</dt>
                                    <dd title="{{ $finishedAt->toIso8601String() }}">{{ $finishedAt->diffForHumans() }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </section>

                {{-- Items list — same item shape as the old inline expand,
                    same click targets (openPayload / openFailed / openPending).
                    Opening a per-item modal closes this batch modal in one
                    server round-trip via the open* methods. --}}
                <section>
                    <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Items ({{ count($items) }})</p>
                    @if (count($items) === 0)
                        <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center text-xs text-gray-500">
                            No tracked items for this batch yet — the per-uuid list expires {{ (int) (\SanderMuller\QueueInsights\Support\Config::int('batches.ttl_seconds', 604800) / 86400) }}d after enqueue.
                        </div>
                    @else
                        <ul role="list" class="overflow-hidden rounded-xl divide-y divide-gray-950/5 ring-1 ring-gray-950/5">
                            @foreach ($items as $i => $item)
                                @php
                                    $status = $item['status'];
                                    $klass = $item['class'] ?? null;
                                    $klassLabel = is_string($klass) && $klass !== '' ? $klass : (string) $item['uuid'];
                                    $shortUuid = substr((string) $item['uuid'], -8);
                                    $ts = $item['timestamp'] ?? null;

                                    [$icon, $iconCls] = match ($status) {
                                        'completed' => ['✓', 'text-emerald-600'],
                                        'failed' => ['✗', 'text-red-600'],
                                        'in_flight' => ['▶', 'text-amber-600'],
                                        default => ['⌛', 'text-gray-400'],
                                    };

                                    // Pre-resolve which Livewire action this row routes to
                                    // (or none if the per-status data isn't ready yet). Pass
                                    // the typed argument through to Blade — `@js()` handles
                                    // the JS-string escaping that raw `e()` doesn't.
                                    $itemAction = null;
                                    if ($status === 'completed' && is_string($item['stream_id'] ?? null)) {
                                        $itemAction = ['method' => 'openPayload', 'arg' => $item['stream_id']];
                                    } elseif ($status === 'failed' && is_int($item['failed_id'] ?? null)) {
                                        $itemAction = ['method' => 'openFailed', 'arg' => (int) $item['failed_id']];
                                    } elseif (($status === 'pending' || $status === 'in_flight') && is_string($item['uuid'] ?? null) && $item['uuid'] !== '') {
                                        $itemAction = ['method' => 'openPending', 'arg' => $item['uuid']];
                                    }
                                @endphp
                                <li @class([
                                        'flex items-start gap-3 bg-white p-3 text-xs',
                                        'cursor-pointer hover:bg-gray-50' => $itemAction !== null,
                                    ])
                                    @if ($itemAction !== null && $itemAction['method'] === 'openPayload')
                                        wire:click="openPayload(@js($itemAction['arg']))"
                                    @elseif ($itemAction !== null && $itemAction['method'] === 'openFailed')
                                        wire:click="openFailed({{ (int) $itemAction['arg'] }})"
                                    @elseif ($itemAction !== null && $itemAction['method'] === 'openPending')
                                        wire:click="openPending(@js($itemAction['arg']))"
                                    @endif>
                                    <span aria-hidden="true" class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-950/[0.04] text-[11px] font-semibold tabular-nums text-gray-600 ring-1 ring-inset ring-gray-950/10">{{ $i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex items-center gap-1.5">
                                            <span class="{{ $iconCls }} font-mono">{{ $icon }}</span>
                                            <span class="truncate font-mono font-medium text-gray-900">{{ $klassLabel }}</span>
                                            @if ($status === 'in_flight')
                                                <span class="shrink-0 inline-flex items-center gap-1 rounded bg-amber-50 px-1 py-px font-sans text-[10px] font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                                    <span aria-hidden="true" class="inline-block size-1 animate-pulse rounded-full bg-amber-500"></span>
                                                    running
                                                </span>
                                            @endif
                                        </p>
                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 text-gray-400">
                                            <span class="font-mono">…{{ $shortUuid }}</span>
                                            @if (is_int($ts))
                                                <span title="{{ \Illuminate\Support\Facades\Date::createFromTimestamp($ts)->toIso8601String() }}">{{ \Illuminate\Support\Facades\Date::createFromTimestamp($ts)->diffForHumans() }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($itemAction !== null)
                                        <svg class="mt-1 size-3 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                                        </svg>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
