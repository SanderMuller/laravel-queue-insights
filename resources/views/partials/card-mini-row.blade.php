@php
    /**
     * Compact one-line row for mission-grid summary cards. Renders the same
     * data the full table partials show but trimmed to fit a quadrant card.
     * Click handlers point at the same Livewire methods (openPayload /
     * openFailed / openPending) so opening a modal works identically from a
     * card preview as from the full tab.
     *
     * @var string $type   one of: completed, failed, pending, class
     * @var array  $item
     */
    $type = $type ?? 'completed';
@endphp

@if($type === 'completed')
    @php
        $fqcn = $item['class'] ?? '—';
        $shortName = ($p = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $p + 1) : $fqcn;
    @endphp
    <li class="flex cursor-pointer items-center justify-between gap-3 py-1.5 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 focus-visible:bg-emerald-50/40 dark:focus-visible:bg-emerald-900/30 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500"
        role="button" tabindex="0"
        aria-label="Open completed job details — {{ $fqcn }}"
        wire:click="openPayload(@js($item['_id']))"
        x-on:keydown.enter.prevent="$wire.openPayload(@js($item['_id']))"
        x-on:keydown.space.prevent="$wire.openPayload(@js($item['_id']))">
        <span class="flex min-w-0 items-center gap-2">
            <span class="size-1.5 shrink-0 rounded-full bg-emerald-500"></span>
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span>
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500 dark:text-gray-300">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            <x-queue-insights::qi-time :at="$item['processed_at'] ?? null" format="relative-short" class="text-gray-400 dark:text-gray-400"/>
        </span>
    </li>

@elseif($type === 'failed')
    @php
        $fqcn = $item['display_name'] ?? '—';
        $shortName = ($p = strrpos((string) $fqcn, '\\')) !== false ? substr((string) $fqcn, $p + 1) : (string) $fqcn;
        $clickable = ($item['id'] ?? null) !== null;
        $excShort = ! empty($item['exception_class']) ? class_basename($item['exception_class']) : null;
    @endphp
    <li @class([
            'flex items-center justify-between gap-3 py-1.5 transition',
            'cursor-pointer hover:bg-gray-950/[0.03] dark:hover:bg-white/5 focus-visible:bg-emerald-50/40 dark:focus-visible:bg-emerald-900/30 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500' => $clickable,
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
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span>
            @if($excShort)
                <span class="hidden truncate font-mono text-[10px] text-red-600 dark:text-red-400 sm:inline" @if(! empty($item['exception_message'])) title="{{ $item['exception_message'] }}" @endif>{{ $excShort }}</span>
            @endif
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500 dark:text-gray-300">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            <x-queue-insights::qi-time :at="$item['failed_at'] ?? null" format="relative-short" class="text-gray-400 dark:text-gray-400"/>
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
            'cursor-pointer hover:bg-gray-950/[0.03] dark:hover:bg-white/5 focus-visible:bg-emerald-50/40 dark:focus-visible:bg-emerald-900/30 focus-visible:outline focus-visible:-outline-offset-1 focus-visible:outline-2 focus-visible:outline-emerald-500' => $clickable,
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
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span>
            @if($isDelayed && $delayHumanized !== null)
                <x-queue-insights::hint
                    triggerClass="shrink-0 rounded bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 font-sans text-[10px] font-medium text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-600/20 dark:ring-indigo-400/30 cursor-help">
                    in {{ $delayHumanized }}
                    <x-slot:tip>
                        Delayed by <span class="font-medium">{{ $delayHumanized }}</span>.
                        @if($availableCarbon)
                            Runs <x-queue-insights::qi-time :at="$availableCarbon" class="font-medium"/>
                            <x-queue-insights::qi-time :at="$availableCarbon" format="absolute-mono" class="block text-[10px] text-white/60"/>
                        @endif
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums text-gray-500 dark:text-gray-300">
            <span class="truncate font-mono">{{ $item['queue'] ?? '—' }}</span>
            @if($isInFlight && $startedCarbon)
                <x-queue-insights::qi-time :at="$startedCarbon" format="relative-short" prefix="started" class="text-gray-400 dark:text-gray-400"/>
            @elseif($queuedCarbon)
                <x-queue-insights::qi-time :at="$queuedCarbon" format="relative-short" class="text-gray-400 dark:text-gray-400"/>
            @else
                <span class="text-gray-400 dark:text-gray-400">—</span>
            @endif
        </span>
    </li>

@elseif($type === 'class')
    @php
        $fqcn = is_string($item['class'] ?? null) ? $item['class'] : '—';
        $shortName = ($p = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $p + 1) : $fqcn;
        $processed = is_numeric($item['processed_24h'] ?? null) ? (int) $item['processed_24h'] : 0;
        $failed = is_numeric($item['failed_24h'] ?? null) ? (int) $item['failed_24h'] : 0;
        $silenced = ($item['silenced'] ?? false) === true;
    @endphp
    <li class="flex items-center justify-between gap-3 py-1.5">
        <span class="flex min-w-0 items-center gap-2">
            <span class="size-1.5 shrink-0 rounded-full {{ $failed > 0 ? 'bg-red-500' : 'bg-emerald-500' }}"></span>
            <span class="min-w-0 truncate font-mono text-xs font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span>
            @if($silenced)
                <span class="hidden shrink-0 rounded bg-gray-100 px-1 py-px text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 sm:inline">silenced</span>
            @endif
        </span>
        <span class="flex shrink-0 items-baseline gap-2 text-[11px] tabular-nums">
            @if($failed > 0)
                <span class="font-medium text-red-700 dark:text-red-300">{{ number_format($failed) }}</span>
                <span class="text-gray-400 dark:text-gray-400">failed</span>
            @endif
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ number_format($processed) }}</span>
            <span class="text-gray-400 dark:text-gray-400">runs</span>
        </span>
    </li>
@endif
