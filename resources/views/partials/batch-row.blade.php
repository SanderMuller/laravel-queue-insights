@php
    /** @var array<string, mixed> $batch */
    $id = (string) $batch['id'];
    $name = $batch['name'] ?? null;
    $label = is_string($name) && $name !== '' ? $name : 'Batch ' . substr($id, 0, 8);

    $total = (int) $batch['total_jobs'];
    $pending = (int) $batch['pending_jobs'];
    $failed = (int) $batch['failed_jobs'];
    $processed = (int) $batch['processed_jobs'];
    $progress = (int) $batch['progress'];

    $cancelledAt = $batch['cancelled_at'] ?? null;
    $finishedAt = $batch['finished_at'] ?? null;
    $createdAt = $batch['created_at'] ?? null;

    $isCancelled = $cancelledAt instanceof \Carbon\CarbonInterface;
    $isFinished = $finishedAt instanceof \Carbon\CarbonInterface;
    $hasFailures = $failed > 0;

    // Tone follows Laravel's first-failure-cancels semantics: a batch with
    // failures that doesn't allow them is effectively cancelled even if the
    // BatchRepository hasn't stamped cancelled_at yet.
    if ($isCancelled || ($hasFailures && ! ($batch['allows_failures'] ?? false))) {
        $barTone = 'bg-red-500';
        $statusChip = ['label' => $isCancelled ? 'cancelled' : 'cancelled (first failure)', 'cls' => 'bg-red-50 text-red-700 ring-red-600/20'];
    } elseif ($isFinished && ! $hasFailures) {
        $barTone = 'bg-emerald-500';
        $statusChip = ['label' => 'finished', 'cls' => 'bg-gray-50 text-gray-700 ring-gray-950/10'];
    } elseif ($hasFailures) {
        $barTone = 'bg-amber-500';
        $statusChip = null;
    } else {
        $barTone = 'bg-emerald-500';
        $statusChip = null;
    }
@endphp
<li class="grid grid-cols-12 items-center gap-4 px-4 py-3 cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500"
    role="button"
    tabindex="0"
    aria-label="Open batch details for {{ $label }}"
    wire:click="toggleBatchInspector(@js($id))"
    x-on:keydown.enter.prevent="$wire.toggleBatchInspector(@js($id))"
    x-on:keydown.space.prevent="$wire.toggleBatchInspector(@js($id))">
    <div class="col-span-5 min-w-0">
        <p class="truncate text-sm font-medium text-gray-900">{{ $label }}</p>
        <p class="truncate font-mono text-xs text-gray-400">{{ $id }}</p>
    </div>

    <div class="col-span-3">
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-950/5">
            <div class="h-full {{ $barTone }} transition-all" style="width: {{ max(2, $progress) }}%"></div>
        </div>
        <p class="mt-1 text-xs tabular-nums text-gray-500">{{ $progress }}% · {{ $processed }}/{{ $total }}</p>
    </div>

    <dl class="col-span-2 grid grid-cols-2 text-center text-xs tabular-nums">
        <div>
            <dt class="text-gray-400">failed</dt>
            <dd class="font-medium {{ $hasFailures ? 'text-red-700' : 'text-gray-700' }}">{{ $failed }}</dd>
        </div>
        <div>
            <dt class="text-gray-400">pending</dt>
            <dd class="font-medium text-gray-700">{{ $pending }}</dd>
        </div>
    </dl>

    <div class="col-span-2 flex flex-wrap items-center justify-end gap-1.5 text-xs">
        @if ($statusChip)
            <span class="rounded {{ $statusChip['cls'] }} px-1.5 py-0.5 font-medium ring-1 ring-inset">
                {{ $statusChip['label'] }}
            </span>
        @endif
        @if ($finishedAt instanceof \Carbon\CarbonInterface)
            <span class="basis-full text-right text-xs text-gray-400" title="{{ $finishedAt->toIso8601String() }}">finished {{ $finishedAt->diffForHumans() }}</span>
        @elseif ($createdAt instanceof \Carbon\CarbonInterface)
            <span class="basis-full text-right text-xs text-gray-400" title="{{ $createdAt->toIso8601String() }}">created {{ $createdAt->diffForHumans() }}</span>
        @endif
    </div>
</li>
