@php
    /** @var array<string, mixed> $row */
    $runtime = $row['duration_ms'] ?? '';
    $runtimeShort = is_numeric($runtime) && (int) $runtime > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $runtime)->cascade()->forHumans(['short' => true])
        : '—';
    $attempts = is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : null;
    $processedAt = $row['processed_at'] ?? null;
    $fqcn = $row['class'] ?? $selectedClass ?? '—';
    $lastBackslash = strrpos($fqcn, '\\');
    $namespace = $lastBackslash !== false ? substr($fqcn, 0, $lastBackslash + 1) : '';
    $shortName = $lastBackslash !== false ? substr($fqcn, $lastBackslash + 1) : $fqcn;

    /** @var array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string}|null $chain */
    $chain = is_array($row['chain'] ?? null) ? $row['chain'] : null;
@endphp
<x-queue-insights::list-row
    wire-action="openPayload"
    :wire-arg="$row['_id']"
    aria-label="Open job details"
    :sr-name="$fqcn">
    <div class="col-span-5 min-w-0">
        {{-- Tight inline: zero whitespace between namespace and leaf so the
            mono-font space gap doesn't appear between them. --}}
        <p class="truncate font-mono text-sm">@if($namespace !== '')<span class="text-gray-400 dark:text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900 dark:text-gray-100">{{ $shortName }}</span></p>
        <p class="mt-0.5 flex items-center gap-1.5">
            @if(! empty($row['short_id']))
                <span class="font-mono text-xs text-gray-400 dark:text-gray-400">#{{ $row['short_id'] }}</span>
            @endif
            @if(! empty($row['batch_id']))
                @include('queue-insights::partials.batch-chip', ['batchId' => $row['batch_id']])
            @endif
            @if($attempts !== null && $attempts > 1)
                @include('queue-insights::partials.retry-chip', ['attempts' => $attempts, 'context' => 'completed'])
            @endif
            @if($chain !== null)
                @include('queue-insights::partials.chain-chip-forward', ['chain' => $chain])
            @endif
        </p>
    </div>
    <div class="col-span-2 min-w-0">
        <p class="truncate text-xs text-gray-500 dark:text-gray-300">{{ $row['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800 dark:text-gray-200">{{ $row['queue'] ?? '—' }}</p>
    </div>
    <div class="col-span-2 text-right">
        <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $runtimeShort }}</p>
    </div>
    <div class="col-span-2 text-right">
        <x-queue-insights::qi-time :at="$processedAt" class="block whitespace-nowrap text-xs text-gray-700 dark:text-gray-300"/>
    </div>
    <div class="col-span-1 text-right">
        <svg class="ml-auto inline-block size-3 text-gray-400 dark:text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
        </svg>
    </div>
</x-queue-insights::list-row>
