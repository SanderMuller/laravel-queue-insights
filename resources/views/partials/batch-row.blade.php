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
        $statusChip = ['label' => $isCancelled ? 'cancelled' : 'cancelled (first failure)', 'cls' => 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-red-600/20 dark:ring-red-400/30'];
    } elseif ($isFinished && ! $hasFailures) {
        $barTone = 'bg-emerald-500';
        $statusChip = ['label' => 'finished', 'cls' => 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'];
    } elseif ($hasFailures) {
        $barTone = 'bg-amber-500';
        $statusChip = null;
    } else {
        $barTone = 'bg-emerald-500';
        $statusChip = null;
    }
@endphp
<x-queue-insights::list-row
    wire-action="toggleBatchInspector"
    :wire-arg="$id"
    :aria-label="'Open batch details for ' . $label"
    density="compact">
    <div class="col-span-5 min-w-0">
        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</p>
        <p class="truncate font-mono text-xs text-gray-400 dark:text-gray-400">{{ $id }}</p>
    </div>

    <div class="col-span-3">
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-950/5 dark:bg-white/10">
            <div class="h-full {{ $barTone }} transition-all" style="width: {{ max(2, $progress) }}%"></div>
        </div>
        <p class="mt-1 text-sm tabular-nums text-gray-500 dark:text-gray-300">{{ $progress }}% · {{ $processed }}/{{ $total }}</p>
    </div>

    <dl class="col-span-2 grid grid-cols-2 text-center text-sm tabular-nums">
        <div>
            <dt class="text-gray-400 dark:text-gray-400">failed</dt>
            <dd class="font-medium {{ $hasFailures ? 'text-red-700 dark:text-red-300' : 'text-gray-700 dark:text-gray-300' }}">{{ $failed }}</dd>
        </div>
        <div>
            <dt class="text-gray-400 dark:text-gray-400">pending</dt>
            <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $pending }}</dd>
        </div>
    </dl>

    <div class="col-span-2 flex flex-wrap items-center justify-end gap-1.5 text-sm">
        @if($statusChip)
            <span class="rounded {{ $statusChip['cls'] }} px-1.5 py-0.5 font-medium ring-1 ring-inset">
                {{ $statusChip['label'] }}
            </span>
        @endif
        @if($finishedAt instanceof \Carbon\CarbonInterface)
            <x-queue-insights::qi-time :at="$finishedAt" prefix="finished" class="basis-full text-right text-sm text-gray-400 dark:text-gray-400"/>
        @elseif($createdAt instanceof \Carbon\CarbonInterface)
            <x-queue-insights::qi-time :at="$createdAt" prefix="created" class="basis-full text-right text-sm text-gray-400 dark:text-gray-400"/>
        @endif
    </div>
</x-queue-insights::list-row>
