@php
    /**
     * Compact one-line row for mission-grid summary cards. Renders the same
     * data the full table partials show but trimmed to fit a quadrant card.
     * Click handlers point at the same Livewire methods (openPayload /
     * openFailed / openPending) so opening a modal works identically from a
     * card preview as from the full tab.
     *
     * @var string $type   one of: completed, failed, pending, queue
     * @var array  $item
     */
    $type = $type ?? 'completed';
@endphp

@if($type === 'completed')
    @php
        $fqcn = $item['class'] ?? '—';
        $shortName = ($p = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $p + 1) : $fqcn;
        try {
            $atHuman = is_string($item['processed_at'] ?? null) && $item['processed_at'] !== ''
                ? \Illuminate\Support\Facades\Date::parse($item['processed_at'])->diffForHumans(['short' => true])
                : '—';
        } catch (\Throwable) { $atHuman = '—'; }
    @endphp
    <li class="flex cursor-pointer items-center justify-between gap-3 py-1.5 transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500"
        role="button" tabindex="0"
        aria-label="Open completed job details — {{ $fqcn }}"
        wire:click="openPayload(@js($item['_id']))"
        x-on:keydown.enter.prevent="$wire.openPayload(@js($item['_id']))"
        x-on:keydown.space.prevent="$wire.openPayload(@js($item['_id']))">
        <span class="flex min-w-0 items-center gap-2">
            <span class="size-1.5 shrink-0 rounded-full bg-emerald-500"></span>
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900">{{ $shortName }}</span>
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            <span class="text-gray-400">{{ $atHuman }}</span>
        </span>
    </li>

@elseif($type === 'failed')
    @php
        $fqcn = $item['display_name'] ?? '—';
        $shortName = ($p = strrpos((string) $fqcn, '\\')) !== false ? substr((string) $fqcn, $p + 1) : (string) $fqcn;
        $clickable = ($item['id'] ?? null) !== null;
        try {
            $atHuman = is_string($item['failed_at'] ?? null) && $item['failed_at'] !== ''
                ? \Illuminate\Support\Facades\Date::parse($item['failed_at'])->diffForHumans(['short' => true])
                : '—';
        } catch (\Throwable) { $atHuman = '—'; }
        $excShort = ! empty($item['exception_class']) ? class_basename($item['exception_class']) : null;
    @endphp
    <li @class([
            'flex items-center justify-between gap-3 py-1.5 transition',
            'cursor-pointer hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500' => $clickable,
        ])
        @if($clickable)
            role="button" tabindex="0"
            aria-label="Open failed job details — {{ $fqcn }}"
            wire:click="openFailed({{ $item['id'] }})"
            x-on:keydown.enter.prevent="$wire.openFailed({{ $item['id'] }})"
            x-on:keydown.space.prevent="$wire.openFailed({{ $item['id'] }})"
        @endif>
        <span class="flex min-w-0 items-center gap-2">
            <span class="size-1.5 shrink-0 rounded-full bg-red-500"></span>
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900">{{ $shortName }}</span>
            @if($excShort)
                <span class="hidden truncate font-mono text-[10px] text-red-600 sm:inline" @if(! empty($item['exception_message'])) title="{{ $item['exception_message'] }}" @endif>{{ $excShort }}</span>
            @endif
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            <span class="text-gray-400">{{ $atHuman }}</span>
        </span>
    </li>

@elseif($type === 'pending')
    @php
        $fqcn = is_string($item['class'] ?? null) ? $item['class'] : '—';
        $shortName = ($p = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $p + 1) : $fqcn;
        $clickable = is_string($item['uuid'] ?? null) && $item['uuid'] !== '';
        $isInFlight = ! empty($item['_isInFlight']);
        $queuedAt = (int) ($item['queued_at'] ?? 0);
        $availableAt = (int) ($item['available_at'] ?? 0);
        $startedAt = isset($item['started_at']) && is_numeric($item['started_at']) ? (int) $item['started_at'] : 0;
        $now = \Illuminate\Support\Facades\Date::now()->getTimestamp();
        // `available_at > now` is the delayed gate, mirrors the full
        // pending-row + pending-modal treatments. In-flight wins because
        // a worker has already picked the job up.
        $isDelayed = ! $isInFlight && $availableAt > $now;
        $queuedCarbon = $queuedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($queuedAt) : null;
        $availableCarbon = $availableAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($availableAt) : null;
        $startedCarbon = $startedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($startedAt) : null;
        $delayHumanized = $isDelayed
            ? \Carbon\CarbonInterval::seconds(max(0, $availableAt - $now))->cascade()->forHumans(['short' => true])
            : null;
    @endphp
    <li @class([
            'flex items-center justify-between gap-3 py-1.5 transition',
            'cursor-pointer hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500' => $clickable,
        ])
        @if($clickable)
            role="button" tabindex="0"
            aria-label="Open {{ $isInFlight ? 'in-flight' : ($isDelayed ? 'delayed' : 'pending') }} job details — {{ $fqcn }}"
            wire:click="openPending(@js($item['uuid']))"
            x-on:keydown.enter.prevent="$wire.openPending(@js($item['uuid']))"
            x-on:keydown.space.prevent="$wire.openPending(@js($item['uuid']))"
        @endif>
        <span class="flex min-w-0 items-center gap-2">
            @if($isInFlight)
                <span class="size-1.5 shrink-0 animate-pulse rounded-full bg-amber-500"></span>
            @elseif($isDelayed)
                <span class="size-1.5 shrink-0 rounded-full bg-indigo-500"></span>
            @else
                <span class="size-1.5 shrink-0 rounded-full bg-gray-400"></span>
            @endif
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900">{{ $shortName }}</span>
            @if($isDelayed && $delayHumanized !== null)
                <x-queue-insights::hint
                    triggerClass="shrink-0 rounded bg-indigo-50 px-1.5 py-0.5 font-sans text-[10px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20 cursor-help">
                    in {{ $delayHumanized }}
                    <x-slot:tip>
                        Delayed by <span class="font-medium">{{ $delayHumanized }}</span>.
                        @if($availableCarbon)
                            Runs <span class="font-medium">{{ $availableCarbon->diffForHumans() }}</span>
                            <span class="block font-mono text-[10px] text-white/60">{{ $availableCarbon->toIso8601String() }}</span>
                        @endif
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            <span class="text-gray-400">
                @if($isInFlight && $startedCarbon)
                    started {{ $startedCarbon->diffForHumans(['short' => true]) }}
                @elseif($queuedCarbon)
                    {{ $queuedCarbon->diffForHumans(['short' => true]) }}
                @else
                    —
                @endif
            </span>
        </span>
    </li>

@elseif($type === 'queue')
    @php
        $depthNum = is_numeric($item['depth'] ?? null) ? (int) $item['depth'] : 0;
        $depthCls = $depthNum === 0 ? 'text-gray-900' : ($depthNum > 1000 ? 'text-red-700' : 'text-amber-700');
        $statusDot = ($item['error'] ?? false) ? 'bg-red-500' : (($item['stale'] ?? false) ? 'bg-amber-400' : 'bg-emerald-500');
        $statusTxt = ($item['error'] ?? false) ? 'errored' : (($item['stale'] ?? false) ? 'stale' : 'healthy');
    @endphp
    <li class="flex items-center justify-between gap-3 py-1.5">
        <span class="flex min-w-0 items-center gap-2">
            <span class="size-1.5 shrink-0 rounded-full {{ $statusDot }}" title="{{ $statusTxt }}"></span>
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900">{{ $item['queue'] }}</span>
            <span class="hidden shrink-0 truncate text-[10px] text-gray-400 sm:inline">{{ $item['connection'] }}</span>
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums">
            <span class="font-medium {{ $depthCls }}">{{ $item['depth'] }}</span>
            <span class="text-gray-400">depth</span>
        </span>
    </li>
@endif
