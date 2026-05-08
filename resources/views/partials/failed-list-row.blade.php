@php
    /** @var array<string, mixed> $f */
    $fqcn = $f['display_name'] ?? '—';
    $lastBackslash = strrpos((string) $fqcn, '\\');
    $namespace = $lastBackslash !== false ? substr((string) $fqcn, 0, $lastBackslash + 1) : '';
    $shortName = $lastBackslash !== false ? substr((string) $fqcn, $lastBackslash + 1) : (string) $fqcn;
    $clickable = $f['id'] !== null;
    $srName = $fqcn . (! empty($f['exception_class']) ? ' (' . $f['exception_class'] . ')' : '');

    /** @var array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string}|null $chain */
    $chain = is_array($f['chain'] ?? null) ? $f['chain'] : null;
    $chainNextLast = null;
    $chainExtra = 0;
    if ($chain !== null) {
        $nextLastSlash = strrpos($chain['next_class'], '\\');
        $chainNextLast = $nextLastSlash !== false ? substr($chain['next_class'], $nextLastSlash + 1) : $chain['next_class'];
        $chainExtra = max(0, $chain['remaining'] - 1);
    }
@endphp
<x-queue-insights::list-row
    :clickable="$clickable"
    wire-action="openFailed"
    :wire-arg="$f['id']"
    aria-label="Open failed job details"
    :sr-name="$srName">
    <div class="col-span-1">
        <span class="inline-flex size-7 items-center justify-center rounded-full bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30" aria-hidden="true">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
            </svg>
        </span>
    </div>

    {{-- FQCN-first: tight inline class is the headline, exception class + uuid sit
        as a secondary line. --}}
    <div class="col-span-5 min-w-0">
        <p class="truncate font-mono text-sm">@if($namespace !== '')<span class="text-gray-400 dark:text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span></p>
        <p class="mt-0.5 flex items-center gap-1.5 truncate text-xs">
            @if(! empty($f['exception_class']))
                <span class="truncate font-mono font-medium text-red-600 dark:text-red-400" @if(! empty($f['exception_message'])) title="{{ $f['exception_message'] }}" @endif>{{ $f['exception_class'] }}</span>
            @endif
            @if(! empty($f['short_uuid']))
                <span class="text-gray-300 dark:text-gray-500" aria-hidden="true">·</span>
                <span class="font-mono text-gray-400 dark:text-gray-400">#{{ $f['short_uuid'] }}</span>
            @endif
            @if(! empty($f['batch_id']))
                @include('queue-insights::partials.batch-chip', ['batchId' => $f['batch_id']])
            @endif
            @if($chain !== null)
                <span class="inline-flex items-center gap-1 rounded-md bg-gray-950/[0.04] px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10"
                      title="Next: {{ $chain['next_class'] }} ({{ $chain['remaining'] }} chained)">
                    <span aria-hidden="true">↳</span>
                    <span>{{ $chainNextLast }}</span>
                    @if($chainExtra > 0)
                        <span class="text-gray-400 dark:text-gray-400">(+{{ $chainExtra }})</span>
                    @endif
                </span>
            @endif
        </p>
    </div>

    <div class="col-span-3 min-w-0">
        <p class="truncate text-xs text-gray-500 dark:text-gray-300">{{ $f['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800 dark:text-gray-200">{{ $f['queue'] ?? '—' }}</p>
    </div>

    <div class="col-span-2 text-right">
        <x-queue-insights::qi-time :at="$f['failed_at'] ?? null" class="block whitespace-nowrap text-xs text-gray-700 dark:text-gray-300"/>
        @if($f['attempts'] !== null && $f['max_tries'] !== null)
            <p class="mt-0.5 text-xs font-medium tabular-nums text-gray-500 dark:text-gray-300">{{ $f['attempts'] }}/{{ $f['max_tries'] }}</p>
        @endif
    </div>

    <div class="col-span-1 text-right">
        @if($clickable)
            <svg class="ml-auto inline-block size-3 text-gray-400 dark:text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
            </svg>
        @else
            <span class="text-xs text-gray-300 dark:text-gray-500">—</span>
        @endif
    </div>
</x-queue-insights::list-row>
