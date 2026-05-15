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
        $statusChip = ['label' => $isCancelled ? 'cancelled' : 'cancelled (first failure)', 'cls' => 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-red-600/20 dark:ring-red-400/30'];
        $heroChrome = 'from-red-50 ring-red-600/10 dark:from-red-900/40 dark:ring-red-400/30';
    } elseif ($isFinished && ! $hasFailures) {
        $barTone = 'bg-emerald-500';
        $statusChip = ['label' => 'finished', 'cls' => 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'];
        $heroChrome = 'from-emerald-50 ring-emerald-600/10 dark:from-emerald-900/40 dark:ring-emerald-400/30';
    } elseif ($hasFailures) {
        $barTone = 'bg-amber-500';
        $statusChip = null;
        $heroChrome = 'from-amber-50 ring-amber-600/10 dark:from-amber-900/40 dark:ring-amber-400/30';
    } else {
        $barTone = 'bg-emerald-500';
        $statusChip = null;
        $heroChrome = 'from-emerald-50 ring-emerald-600/10 dark:from-emerald-900/40 dark:ring-emerald-400/30';
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
         class="max-h-[88vh] w-full max-w-5xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <div class="flex items-center gap-2">
                <span class="inline-flex size-6 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 ring-1 ring-inset ring-indigo-600/20">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
                    </svg>
                </span>
                <h3 id="qi-batch-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">Batch</h3>
            </div>
            <button type="button"
                    wire:click="closeBatch"
                    aria-label="Close batch modal"
                    class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-close/>
            </button>
        </div>

        @if($row === null)
            <div class="p-4">
                <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center dark:border-white/10">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Batch no longer tracked</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-300">
                        The BatchRepository row aged out, or our index TTL fired before the next poll. Close this modal — the Batches list refreshes on the next 10s tick.
                    </p>
                </div>
            </div>
        @else
            <div class="grid md:grid-cols-[20rem_1fr]">
                {{-- Left rail — identity + progress + counts + timeline. --}}
                <div class="border-b border-gray-950/5 p-5 md:border-b-0 md:border-r dark:border-white/10">
                    <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Batch</p>
                    <p class="break-all text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="ID" :value="$id"/>
                        @if($statusChip)
                            <span class="rounded-md px-1.5 py-0.5 font-medium ring-1 ring-inset {{ $statusChip['cls'] }}">
                                {{ $statusChip['label'] }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-4">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-950/5 dark:bg-white/10">
                            <div class="h-full {{ $barTone }} transition-all" style="width: {{ max(2, $progress) }}%"></div>
                        </div>
                        <p class="mt-1.5 text-xs tabular-nums text-gray-500 dark:text-gray-300">{{ $progress }}% · {{ $processed }}/{{ $total }} processed</p>
                    </div>

                    {{-- Counts row — compact stat trio. --}}
                    <dl class="mt-4 grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5 dark:bg-white/10 dark:ring-white/10">
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Done</dt>
                            <dd class="mt-1 text-base font-semibold tracking-tight tabular-nums text-emerald-700 dark:text-emerald-300">{{ $processed }}</dd>
                        </div>
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Failed</dt>
                            <dd class="mt-1 text-base font-semibold tracking-tight tabular-nums {{ $hasFailures ? 'text-red-700 dark:text-red-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $failed }}</dd>
                        </div>
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Pending</dt>
                            <dd class="mt-1 text-base font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ $pending }}</dd>
                        </div>
                    </dl>

                    {{-- Timeline as a clean description list, matching the
                        completed/failed modal rail. --}}
                    @if($createdAt instanceof \Carbon\CarbonInterface || $finishedAt instanceof \Carbon\CarbonInterface)
                        <dl class="mt-4 divide-y divide-gray-950/5 border-t border-gray-950/5 text-xs dark:divide-white/10 dark:border-white/10">
                            @if($createdAt instanceof \Carbon\CarbonInterface)
                                <div class="flex items-baseline justify-between gap-3 py-2">
                                    <dt class="text-gray-500 dark:text-gray-400">Created</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-gray-100">
                                        <x-queue-insights::qi-time :at="$createdAt"/>
                                    </dd>
                                </div>
                            @endif
                            @if($finishedAt instanceof \Carbon\CarbonInterface)
                                <div class="flex items-baseline justify-between gap-3 py-2">
                                    <dt class="text-gray-500 dark:text-gray-400">{{ $isCancelled ? 'Cancelled' : 'Finished' }}</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-gray-100">
                                        <x-queue-insights::qi-time :at="$finishedAt"/>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>

                {{-- Right column — items list, the focal content.
                    Same item shape as the old inline expand, same click
                    targets (openPayload / openFailed / openPending). --}}
                <div class="min-w-0 p-5">
                    <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Items ({{ count($items) }})</p>
                    @if(count($items) === 0)
                        <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center text-xs text-gray-500 dark:text-gray-300">
                            No tracked items for this batch yet — the per-uuid list expires {{ (int) (\SanderMuller\QueueInsights\Support\Config::int('batches.ttl_seconds', 604800) / 86400) }}d after enqueue.
                        </div>
                    @else
                        <ul role="list" class="overflow-hidden rounded-xl divide-y divide-gray-950/5 dark:divide-white/10 ring-1 ring-gray-950/5 dark:ring-white/10">
                            @foreach($items as $i => $item)
                                @php
                                    $status = $item['status'];
                                    $klass = $item['class'] ?? null;
                                    $klassLabel = is_string($klass) && $klass !== '' ? $klass : (string) $item['uuid'];
                                    $ts = $item['timestamp'] ?? null;

                                    [$icon, $iconCls] = match ($status) {
                                        'completed' => ['✓', 'text-emerald-600 dark:text-emerald-400'],
                                        'failed' => ['✗', 'text-red-600 dark:text-red-400'],
                                        'in_flight' => ['▶', 'text-amber-600 dark:text-amber-400'],
                                        default => ['⌛', 'text-gray-400 dark:text-gray-400'],
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
                                        'flex items-start gap-3 bg-white dark:bg-gray-900 p-3 text-xs',
                                        'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800' => $itemAction !== null,
                                    ])
                                    @if($itemAction !== null && $itemAction['method'] === 'openPayload')
                                        wire:click="openPayload(@js($itemAction['arg']))"
                                    @elseif($itemAction !== null && $itemAction['method'] === 'openFailed')
                                        wire:click="openFailed({{ (int) $itemAction['arg'] }})"
                                    @elseif($itemAction !== null && $itemAction['method'] === 'openPending')
                                        wire:click="openPending(@js($itemAction['arg']))"
                                    @endif>
                                    <span aria-hidden="true" class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-950/[0.04] text-[11px] font-semibold tabular-nums text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">{{ $i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex items-center gap-1.5">
                                            <span class="{{ $iconCls }} font-mono">{{ $icon }}</span>
                                            <span class="truncate font-mono font-medium text-gray-900 dark:text-gray-100">{{ $klassLabel }}</span>
                                            @if($status === 'in_flight')
                                                <span class="shrink-0 inline-flex items-center gap-1 rounded bg-amber-50 dark:bg-amber-900/40 px-1 py-px font-sans text-[10px] font-medium text-amber-700 dark:text-amber-300 ring-1 ring-inset ring-amber-600/20 dark:ring-amber-400/30">
                                                    <span aria-hidden="true" class="inline-block size-1 animate-pulse rounded-full bg-amber-500"></span>
                                                    running
                                                </span>
                                            @endif
                                        </p>
                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 text-gray-400 dark:text-gray-400">
                                            <span class="break-all font-mono">{{ $item['uuid'] }}</span>
                                            @if(is_int($ts))
                                                <x-queue-insights::qi-time :at="$ts"/>
                                            @endif
                                        </div>
                                    </div>
                                    @if($itemAction !== null)
                                        <x-queue-insights::icon-chevron-right class="mt-1 size-3 shrink-0 text-gray-400 dark:text-gray-400"/>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>{{-- /right column --}}
            </div>{{-- /grid --}}
        @endif
    </div>
</div>
