@php
    /** @var array<string, mixed> $f */
    $fqcn = $f['display_name'] ?? '—';
    $lastBackslash = strrpos((string) $fqcn, '\\');
    $namespace = $lastBackslash !== false ? substr((string) $fqcn, 0, $lastBackslash + 1) : '';
    $shortName = $lastBackslash !== false ? substr((string) $fqcn, $lastBackslash + 1) : (string) $fqcn;
    $clickable = $f['id'] !== null;
    $srName = $fqcn . (! empty($f['exception_class']) ? ' (' . $f['exception_class'] . ')' : '');

    // Runtime side-key (`failed-runtime:{uuid}`, 30 d TTL) is best-effort:
    // RecordJobFailed only writes it when `start:{uuid}` was readable. Aged-out
    // or short-circuited failures render `—`.
    $runtime = $f['duration_ms'] ?? null;
    $runtimeShort = is_numeric($runtime) && (int) $runtime > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $runtime)->cascade()->forHumans(['short' => true])
        : '—';
    $attempts = is_numeric($f['attempts'] ?? null) ? (int) $f['attempts'] : null;
    $maxTries = is_numeric($f['max_tries'] ?? null) ? (int) $f['max_tries'] : null;

    /** @var array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string}|null $chain */
    $chain = is_array($f['chain'] ?? null) ? $f['chain'] : null;
@endphp
<x-queue-insights::list-row
    :clickable="$clickable"
    wire-action="openFailed"
    :wire-arg="$f['id']"
    aria-label="Open failed job details"
    :sr-name="$srName">
    {{-- FQCN-first: tight inline class is the headline, exception class + uuid sit
        as a secondary line. Column shape mirrors completed-row (5/2/2/2/1) so the
        two list panes share one chrome. Job column gets the most width because
        FQCN + secondary identifiers + chips compete for space; queue/runtime/
        timestamp data is short and fits in narrower cells. --}}
    <div class="col-span-5 min-w-0">
        <p class="truncate font-mono text-sm">@if($namespace !== '')<span class="text-gray-400 dark:text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span></p>
        <p class="mt-0.5 flex items-center gap-1.5 truncate text-xs">
            @if(! empty($f['exception_class']))
                @if(! empty($f['exception_message']))
                    <x-queue-insights::hint
                        triggerClass="truncate font-mono font-medium text-red-600 dark:text-red-400 cursor-help">
                        {{ $f['exception_class'] }}
                        <x-slot:tip>
                            <span class="block text-gray-300">Exception</span>
                            <span class="block font-mono break-all text-white">{{ $f['exception_class'] }}</span>
                            <span class="mt-1 block break-words text-gray-200">{{ $f['exception_message'] }}</span>
                        </x-slot:tip>
                    </x-queue-insights::hint>
                @else
                    <span class="truncate font-mono font-medium text-red-600 dark:text-red-400">{{ $f['exception_class'] }}</span>
                @endif
            @endif
            @if(! empty($f['short_uuid']))
                <span class="text-gray-300 dark:text-gray-500" aria-hidden="true">·</span>
                @if(! empty($f['uuid']))
                    <x-queue-insights::hint
                        triggerClass="font-mono text-gray-400 dark:text-gray-400 cursor-help">
                        #{{ $f['short_uuid'] }}
                        <x-slot:tip>
                            <span class="block text-gray-300">Job UUID</span>
                            <span class="block font-mono break-all text-white">{{ $f['uuid'] }}</span>
                        </x-slot:tip>
                    </x-queue-insights::hint>
                @else
                    <span class="font-mono text-gray-400 dark:text-gray-400">#{{ $f['short_uuid'] }}</span>
                @endif
            @endif
            @if(! empty($f['batch_id']))
                @include('queue-insights::partials.batch-chip', ['batchId' => $f['batch_id']])
            @endif
            @if($chain !== null)
                @include('queue-insights::partials.chain-chip-forward', ['chain' => $chain])
            @endif
        </p>
    </div>

    <div class="col-span-2 min-w-0">
        <p class="truncate text-xs text-gray-500 dark:text-gray-300">{{ $f['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800 dark:text-gray-200">{{ $f['queue'] ?? '—' }}</p>
    </div>

    <div class="col-span-2 text-right">
        <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $runtimeShort }}</p>
        @if($attempts !== null && $maxTries !== null)
            <p class="mt-0.5 text-xs font-medium tabular-nums text-gray-500 dark:text-gray-300">{{ $attempts }}/{{ $maxTries }} tries</p>
        @elseif($attempts !== null && $attempts > 1)
            <p class="mt-0.5 text-xs font-medium tabular-nums text-amber-700 dark:text-amber-300">{{ $attempts }} tries</p>
        @endif
    </div>

    <div class="col-span-2 text-right">
        <x-queue-insights::qi-time :at="$f['failed_at'] ?? null" class="block whitespace-nowrap text-xs text-gray-700 dark:text-gray-300"/>
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
