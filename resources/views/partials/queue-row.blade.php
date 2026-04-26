@php
    /** @var array<string, mixed> $q */
    $depthNum = is_numeric($q['depth']) ? (int) $q['depth'] : 0;
    $depthCls = $depthNum === 0 ? 'text-gray-900' : ($depthNum > 1000 ? 'text-red-700' : 'text-amber-700');
@endphp
<li class="grid grid-cols-12 items-center gap-4 px-4 py-3 {{ $q['error'] ? 'bg-red-50/30' : ($q['stale'] ? 'bg-amber-50/30' : '') }}">
    <div class="col-span-4 min-w-0">
        <p class="truncate text-xs text-gray-500">{{ $q['connection'] }}</p>
        <p class="truncate font-mono text-sm font-medium text-gray-900">{{ $q['queue'] }}</p>
    </div>
    <dl class="col-span-4 grid grid-cols-3 text-center text-sm tabular-nums">
        <div>
            <dt class="sr-only">Depth</dt>
            <dd class="font-semibold {{ $depthCls }}">{{ $q['depth'] }}</dd>
        </div>
        <div>
            <dt class="sr-only">In-flight</dt>
            <dd class="font-semibold text-gray-900">{{ $q['inflight'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="sr-only">Delayed</dt>
            <dd class="font-semibold text-gray-900">{{ $q['delayed'] ?? '—' }}</dd>
        </div>
    </dl>
    <dl class="col-span-2 text-xs tabular-nums text-gray-500" title="Wait time = enqueue → worker pickup. Most recent 1000 jobs.">
        <div class="flex items-center justify-end gap-1.5">
            <dt class="text-gray-400">p50</dt>
            <dd class="font-medium text-gray-700">{{ $q['wait_p50_ms'] !== null ? number_format($q['wait_p50_ms']).'ms' : '—' }}</dd>
        </div>
        <div class="flex items-center justify-end gap-1.5">
            <dt class="text-gray-400">p95</dt>
            <dd class="font-medium text-gray-700">{{ $q['wait_p95_ms'] !== null ? number_format($q['wait_p95_ms']).'ms' : '—' }}</dd>
        </div>
    </dl>
    <div class="col-span-2 flex flex-wrap items-center justify-end gap-1.5 text-xs">
        @if($q['error'])
            <span class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700 ring-1 ring-inset ring-red-600/20" title="{{ $q['error'] }}">error</span>
        @endif
        @if($q['stale'])
            <span class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">stale</span>
        @endif
        <span class="rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-gray-700">{{ $q['driver'] }}</span>
        @if($q['last_at'])
            <span class="basis-full text-right text-xs text-gray-400" title="{{ $q['last_at']->toIso8601String() }}">last {{ $q['last_at']->diffForHumans() }}</span>
        @else
            <span class="basis-full text-right text-xs text-gray-400">no snapshot yet</span>
        @endif
    </div>
</li>
