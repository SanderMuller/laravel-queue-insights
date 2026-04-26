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
@endphp
<li class="grid grid-cols-12 items-center gap-4 px-4 py-2.5 cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500"
    role="button"
    tabindex="0"
    aria-label="Open job details"
    wire:click="openPayload(@js($row['_id']))"
    x-on:keydown.enter.prevent="$wire.openPayload(@js($row['_id']))"
    x-on:keydown.space.prevent="$wire.openPayload(@js($row['_id']))">
    <span class="sr-only">Details — {{ $fqcn }}</span>
    <div class="col-span-4 min-w-0">
        {{-- Tight inline: zero whitespace between namespace and leaf so the
            mono-font space gap doesn't appear between them. --}}
        <p class="truncate font-mono text-sm">@if ($namespace !== '')<span class="text-gray-400">{{ $namespace }}</span>@endif<span class="font-medium text-gray-900">{{ $shortName }}</span></p>
        @if (! empty($row['short_id']))
            <p class="mt-0.5 font-mono text-xs text-gray-400">#{{ $row['short_id'] }}</p>
        @endif
    </div>
    <div class="col-span-3 min-w-0">
        <p class="truncate text-xs text-gray-500">{{ $row['connection'] ?? '—' }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-gray-800">{{ $row['queue'] ?? '—' }}</p>
    </div>
    <div class="col-span-2 text-right">
        <p class="text-sm font-medium tabular-nums text-gray-900">{{ $runtimeShort }}</p>
        @if ($attempts !== null && $attempts > 1)
            <p class="mt-0.5 text-xs font-medium tabular-nums text-amber-700">{{ $attempts }} tries</p>
        @endif
    </div>
    <div class="col-span-2 text-right">
        <p class="whitespace-nowrap text-xs text-gray-700" @if ($processedAt) title="{{ $processedAt }}" @endif>{{ $atHuman ?? '—' }}</p>
    </div>
    <div class="col-span-1 text-right">
        <svg class="ml-auto inline-block size-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
        </svg>
    </div>
</li>
