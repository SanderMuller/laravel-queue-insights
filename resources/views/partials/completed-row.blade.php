@php
    /** @var array<string, mixed> $row */
    $runtime = $row['duration_ms'] ?? '';
    $runtimeShort = is_numeric($runtime) && (int) $runtime > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $runtime)->cascade()->forHumans(['short' => true])
        : '—';
    $attempts = is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : null;
    $processedAt = $row['processed_at'] ?? null;
    try {
        $atHuman = is_string($processedAt) && $processedAt !== ''
            ? \Illuminate\Support\Facades\Date::parse($processedAt)->diffForHumans()
            : null;
    } catch (\Throwable) {
        $atHuman = null;
    }
    $fqcn = $row['class'] ?? $selectedClass ?? '—';
    $lastBackslash = strrpos($fqcn, '\\');
    $namespace = $lastBackslash !== false ? substr($fqcn, 0, $lastBackslash + 1) : '';
    $shortName = $lastBackslash !== false ? substr($fqcn, $lastBackslash + 1) : $fqcn;

    /** @var array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string}|null $chain */
    $chain = is_array($row['chain'] ?? null) ? $row['chain'] : null;
    $chainNextLast = null;
    $chainExtra = 0;
    if ($chain !== null) {
        $nextLastSlash = strrpos($chain['next_class'], '\\');
        $chainNextLast = $nextLastSlash !== false ? substr($chain['next_class'], $nextLastSlash + 1) : $chain['next_class'];
        $chainExtra = max(0, $chain['remaining'] - 1);
    }
@endphp
<x-queue-insights::list-row
    wire-action="openPayload"
    :wire-arg="$row['_id']"
    aria-label="Open job details"
    :sr-name="$fqcn">
    <div class="col-span-4 min-w-0">
        {{-- Tight inline: zero whitespace between namespace and leaf so the
            mono-font space gap doesn't appear between them. --}}
        <p class="truncate font-mono text-sm">@if($namespace !== '')<span class="text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900">{{ $shortName }}</span></p>
        <p class="mt-0.5 flex items-center gap-1.5">
            @if(! empty($row['short_id']))
                <span class="font-mono text-xs text-gray-400">#{{ $row['short_id'] }}</span>
            @endif
            @if(! empty($row['batch_id']))
                @include('queue-insights::partials.batch-chip', ['batchId' => $row['batch_id']])
            @endif
            @if($chain !== null)
                <span class="inline-flex items-center gap-1 rounded-md bg-gray-950/[0.04] px-1.5 py-0.5 font-mono text-[10px] text-gray-600 ring-1 ring-inset ring-gray-950/10"
                      title="Next: {{ $chain['next_class'] }} ({{ $chain['remaining'] }} chained)">
                    <span aria-hidden="true">↳</span>
                    <span>{{ $chainNextLast }}</span>
                    @if($chainExtra > 0)
                        <span class="text-gray-400">(+{{ $chainExtra }})</span>
                    @endif
                </span>
            @endif
        </p>
    </div>
    <div class="col-span-3 min-w-0">
        <p class="truncate text-xs text-gray-500">{{ $row['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800">{{ $row['queue'] ?? '—' }}</p>
    </div>
    <div class="col-span-2 text-right">
        <p class="text-sm font-medium tabular-nums text-gray-900">{{ $runtimeShort }}</p>
        @if($attempts !== null && $attempts > 1)
            <p class="mt-0.5 text-xs font-medium tabular-nums text-amber-700">{{ $attempts }} tries</p>
        @endif
    </div>
    <div class="col-span-2 text-right">
        <p class="whitespace-nowrap text-xs text-gray-700" @if($processedAt) title="{{ $processedAt }}" @endif>{{ $atHuman ?? '—' }}</p>
    </div>
    <div class="col-span-1 text-right">
        <svg class="ml-auto inline-block size-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
        </svg>
    </div>
</x-queue-insights::list-row>
