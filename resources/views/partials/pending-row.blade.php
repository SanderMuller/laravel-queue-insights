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

    $shortUuid = is_string($row['uuid'] ?? null) ? substr($row['uuid'], 0, 8) : null;
    $clickable = is_string($row['uuid'] ?? null) && $row['uuid'] !== '';

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
    <div class="col-span-5 min-w-0">
        <p class="flex items-center gap-1.5 truncate font-mono text-sm">
            <span class="truncate">@if($namespace !== '')<span class="text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900">{{ $shortName }}</span></span>
            @if($isInFlight)
                <span class="shrink-0 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 font-sans text-[10px] font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20"
                      @if($startedCarbon) title="Started {{ $startedCarbon->toIso8601String() }}" @endif>
                    <span aria-hidden="true" class="inline-block size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                    running
                </span>
            @elseif($isDelayed)
                <span class="shrink-0 rounded bg-indigo-50 px-1.5 py-0.5 font-sans text-[10px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20"
                      @if($availableCarbon) title="Runs {{ $availableCarbon->toIso8601String() }}" @endif>
                    delayed
                </span>
            @endif
        </p>
        @if($shortUuid !== null)
            <p class="mt-0.5 font-mono text-xs text-gray-400">#{{ $shortUuid }}</p>
        @endif
    </div>
    <div class="col-span-3 min-w-0">
        <p class="truncate text-xs text-gray-500">{{ $row['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800">{{ $row['queue'] ?? '—' }}</p>
    </div>
    <div class="col-span-2 text-right">
        <p class="whitespace-nowrap text-xs text-gray-700"
           @if($queuedCarbon) title="{{ $queuedCarbon->toIso8601String() }}" @endif>
            {{ $queuedCarbon?->diffForHumans() ?? '—' }}
        </p>
    </div>
    <div class="col-span-2 text-right">
        @if($isInFlight)
            <p class="whitespace-nowrap text-xs text-gray-700"
               @if($startedCarbon) title="{{ $startedCarbon->toIso8601String() }}" @endif>
                started {{ $startedCarbon?->diffForHumans() ?? '—' }}
            </p>
        @else
            <p class="whitespace-nowrap text-xs text-gray-700"
               @if($availableCarbon) title="{{ $availableCarbon->toIso8601String() }}" @endif>
                {{ $availableCarbon?->diffForHumans() ?? '—' }}
            </p>
        @endif
    </div>
</x-queue-insights::list-row>
