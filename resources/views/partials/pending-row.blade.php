@php
    /** @var array<string, mixed> $row */
    /** @var bool $isDelayed */
    /** @var bool $isInFlight */
    $isDelayed = $isDelayed ?? false;
    $isInFlight = $isInFlight ?? false;
    $fqcn = is_string($row['class'] ?? null) ? $row['class'] : '—';
    $lastBackslash = strrpos($fqcn, '\\');
    $namespace = $lastBackslash !== false ? substr($fqcn, 0, $lastBackslash + 1) : '';
    $shortName = $lastBackslash !== false ? substr($fqcn, $lastBackslash + 1) : $fqcn;

    $queuedAt = (int) ($row['queued_at'] ?? 0);
    $availableAt = (int) ($row['available_at'] ?? 0);
    $startedAt = isset($row['started_at']) && is_numeric($row['started_at']) ? (int) $row['started_at'] : 0;
    $queuedCarbon = $queuedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($queuedAt) : null;
    $availableCarbon = $availableAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($availableAt) : null;
    $startedCarbon = $startedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($startedAt) : null;

    $fullUuid = is_string($row['uuid'] ?? null) && $row['uuid'] !== '' ? $row['uuid'] : null;
    $clickable = $fullUuid !== null;

    // `attempts` is stamped on the pending hash by RecordJobProcessing's Lua
    // script (`MarkInFlight.lua`). > 1 means the worker has already picked
    // this job up at least once and either failed-with-retry or `release()`d
    // — i.e. this is a retry attempt. Null on legacy rows that pre-date the
    // attempts stamp (rare; rolls forward as workers cycle).
    $attempts = isset($row['attempts']) && is_numeric($row['attempts']) ? (int) $row['attempts'] : null;
    $isRetry = $attempts !== null && $attempts > 1;

    // Batch + chain context — surfaced inline as chips so operators see at a
    // glance whether a pending/in-flight job is part of a wider workflow.
    // `batch_id` is the job_batches uuid; `parent_uuid` is the dispatching
    // chain link's uuid (set by the lineage subsystem in RecordJobQueued).
    $batchId = is_string($row['batch_id'] ?? null) && $row['batch_id'] !== '' ? $row['batch_id'] : null;
    $parentUuid = is_string($row['parent_uuid'] ?? null) && $row['parent_uuid'] !== '' ? $row['parent_uuid'] : null;
    $parentClass = is_string($row['parent_class'] ?? null) && $row['parent_class'] !== '' ? $row['parent_class'] : null;
    $parentClassShort = $parentClass !== null && str_contains($parentClass, '\\')
        ? substr($parentClass, strrpos($parentClass, '\\') + 1)
        : $parentClass;

    // Per-row a11y label — surfaces the row's state to assistive tech without
    // depending on the surrounding section header.
    $stateLabel = match (true) {
        $isInFlight => 'in-flight',
        $isDelayed => 'delayed',
        default => 'pending',
    };
@endphp
<x-queue-insights::list-row
    :clickable="$clickable"
    wire-action="openPending"
    :wire-arg="$row['uuid'] ?? null"
    :aria-label="'Open ' . $stateLabel . ' job details'">
    <div class="col-span-5 flex min-w-0 items-start gap-3">
        {{-- Leading state indicator — three flavors:
              · in-flight → emerald radar pulse (Aurora micro-pulse)
              · delayed   → indigo clock icon (snoozing until availableAt)
              · pending   → static emerald dot (queued, no worker yet)
             A fixed-width slot keeps the class-name column aligned across rows. --}}
        <span class="relative mt-[5px] flex size-3 shrink-0 items-center justify-center" aria-hidden="true">
            @if($isInFlight)
                <span class="pointer-events-none absolute -inset-1">
                    <span class="absolute inset-0 rounded-full ring-1 ring-emerald-500/60 dark:ring-emerald-400/60" style="animation: qi-radar-sm 2s ease-out infinite;"></span>
                    <span class="absolute inset-0 rounded-full ring-1 ring-emerald-500/60 dark:ring-emerald-400/60" style="animation: qi-radar-sm 2s ease-out infinite; animation-delay: 1s;"></span>
                </span>
                <span class="relative inline-flex size-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
            @elseif($isDelayed)
                {{-- Heroicons mini clock — "snoozing until availableAt". --}}
                <svg class="size-3 text-indigo-500 dark:text-indigo-300" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                </svg>
            @else
                <span class="inline-flex size-1.5 rounded-full bg-emerald-500/60 dark:bg-emerald-400/60"></span>
            @endif
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate font-mono text-sm">
                @if($namespace !== '')<span class="text-gray-400 dark:text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span>
            </p>
            <p class="mt-0.5 flex items-center gap-1.5">
                @if($fullUuid !== null)
                    <span class="min-w-0 truncate font-mono text-xs text-gray-400 dark:text-gray-400" title="{{ $fullUuid }}">#{{ $fullUuid }}</span>
                @endif
                @if($isDelayed)
                @php
                    $delaySeconds = max(0, $availableAt - $queuedAt);
                    $delayHuman = $delaySeconds > 0
                        ? \Carbon\CarbonInterval::seconds($delaySeconds)->cascade()->forHumans(['short' => true, 'parts' => 2])
                        : '—';
                @endphp
                <x-queue-insights::hint
                    triggerClass="shrink-0 inline-flex items-center rounded bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 font-sans text-[10px] font-medium text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-600/20 dark:ring-indigo-400/30 cursor-help">
                    delayed
                    <x-slot:tip>
                        <span class="block font-medium text-white">Delayed run</span>
                        <span class="mt-1 block text-gray-300">Total delay <span class="font-mono">{{ $delayHuman }}</span></span>
                        @if($queuedCarbon !== null)
                            <span class="mt-1 block text-gray-400">
                                Queued <x-queue-insights::qi-time :at="$queuedCarbon" format="absolute-mono" class="text-white"/>
                            </span>
                        @endif
                        @if($availableCarbon !== null)
                            <span class="mt-1 block text-gray-400">
                                Runs <x-queue-insights::qi-time :at="$availableCarbon" format="absolute-mono" class="text-white"/>
                                (<x-queue-insights::qi-time :at="$availableCarbon" class="text-gray-300"/>)
                            </span>
                        @endif
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
            @if($batchId !== null)
                @include('queue-insights::partials.batch-chip', ['batchId' => $batchId])
            @endif
            @if($parentUuid !== null)
                {{-- Backward direction (↰) — pending rows have parent_uuid but not
                     the forward `chained` payload that completed/failed expose. --}}
                <x-queue-insights::hint
                    triggerClass="shrink-0 inline-flex items-center gap-1 rounded-md bg-gray-950/[0.04] dark:bg-white/10 px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 cursor-help">
                    <span aria-hidden="true">↰</span>
                    <span>{{ $parentClassShort ?? 'chain' }}</span>
                    <x-slot:tip>
                        <span class="block text-gray-300">Part of a chain</span>
                        @if($parentClass !== null)
                            <span class="block font-mono break-all text-white">{{ $parentClass }}</span>
                        @endif
                        <span class="mt-1 block text-gray-400">Dispatched by a parent in a <span class="font-mono">Bus::chain</span> — open this job's modal to see the full lineage.</span>
                        <span class="mt-1 block text-gray-400">Parent uuid <span class="font-mono break-all text-white">{{ $parentUuid }}</span></span>
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
            @if($isRetry)
                @include('queue-insights::partials.retry-chip', ['attempts' => $attempts, 'context' => 'pickup'])
            @endif
        </p>
        </div>
    </div>
    <div class="col-span-3 min-w-0">
        <p class="truncate text-sm text-gray-500 dark:text-gray-300">{{ $row['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-sm text-gray-800 dark:text-gray-200">{{ $row['queue'] ?? '—' }}</p>
    </div>
    <div class="col-span-2 text-right">
        <x-queue-insights::qi-time :at="$queuedCarbon" class="block whitespace-nowrap text-sm text-gray-700 dark:text-gray-300"/>
    </div>
    <div class="col-span-2 text-right">
        @if($isInFlight)
            <x-queue-insights::qi-time :at="$startedCarbon" prefix="started" class="block whitespace-nowrap text-sm text-gray-700 dark:text-gray-300"/>
        @else
            <x-queue-insights::qi-time :at="$availableCarbon" class="block whitespace-nowrap text-sm text-gray-700 dark:text-gray-300"/>
        @endif
    </div>
</x-queue-insights::list-row>
