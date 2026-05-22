@php
    /**
     * Overview pane — mission-grid summary cards. Most cards list a handful
     * of clickable preview rows that open the same modal as the full section;
     * every card carries a "See all N →" control that jumps to the matching
     * section via the URL hash.
     *
     * Required scope vars:
     *   $pendingEnabled, $hasPendingAny, $inFlightRows, $pendingRows, $delayedRows
     *   $pendingPreview                     — top-N pending rows for the Pending card
     *   $completedPreview, $failedPreview   — top-5 of the unsliced post-filter
     *                                          recent lists. Distinct from
     *                                          `$completedRows`/`$failedRows`
     *                                          which are paginated slices and
     *                                          would shift after the user
     *                                          navigates pages.
     *   $completedTotal, $failedTotal
     *   $classes                            — per-class 24h aggregates; the
     *                                          Classes card previews the busiest.
     *   $stats
     */

    // Classes card preview — busiest classes by 24h processed volume.
    // `jobClasses()` returns the roster unsorted, so sort here rather than
    // slicing an arbitrary order.
    $classesByVolume = collect($classes)
        ->sortByDesc(fn (array $c): int => (int) ($c['processed_24h'] ?? 0))
        ->values();
    $classesPreview = $classesByVolume->take(5)->all();
    $classesProcessed = $classesByVolume->sum(fn (array $c): int => (int) ($c['processed_24h'] ?? 0));
    $classesFailing = $classesByVolume->filter(fn (array $c): bool => (int) ($c['failed_24h'] ?? 0) > 0)->count();
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    {{-- Classes card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Classes</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ count($classes) }}</p>
            </div>
            @if($classesFailing > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                    <span class="size-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                    {{ $classesFailing }} failing
                </span>
            @else
                <span class="text-[10px] font-medium text-gray-400 dark:text-gray-500">24h window</span>
            @endif
        </div>
        @if(count($classesPreview) === 0)
            <p class="py-2 text-sm text-gray-500 dark:text-gray-300">No processed jobs in the window.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($classesPreview as $c)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'class', 'item' => $c])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <span class="text-[11px] tabular-nums text-gray-500 dark:text-gray-300">{{ number_format($classesProcessed) }} processed · 24h</span>
            <button type="button"
                    x-on:click="setTab('classes')"
                    class="rounded text-sm font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ count($classes) }} →
            </button>
        </div>
    </div>

    {{-- Pending card --}}
    @if($pendingEnabled)
        <div class="flex flex-col gap-2 rounded-xl bg-white p-5 ring-1 dark:bg-gray-900 {{ count($inFlightRows) > 0 ? 'ring-amber-600/15 dark:ring-amber-400/30' : 'ring-gray-950/5 dark:ring-white/10' }}">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Pending</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ count($inFlightRows) + count($pendingRows) + count($delayedRows) }}</p>
                </div>
                @if(count($inFlightRows) > 0)
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                        <span class="size-1.5 animate-pulse rounded-full bg-amber-500 dark:bg-amber-400"></span>
                        {{ count($inFlightRows) }} in-flight
                    </span>
                @endif
            </div>
            @if(! $hasPendingAny)
                <p class="py-2 text-sm text-gray-500 dark:text-gray-300">No pending jobs tracked.</p>
            @else
                <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach($pendingPreview as $p)
                        @include('queue-insights::partials.card-mini-row', ['type' => 'pending', 'item' => $p])
                    @endforeach
                </ul>
            @endif
            <div class="mt-auto flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
                <span class="text-[11px] tabular-nums text-gray-500 dark:text-gray-300">
                    {{ count($inFlightRows) }} in-flight · {{ count($pendingRows) }} pending · {{ count($delayedRows) }} delayed
                </span>
                <button type="button"
                        x-on:click="setTab('pending')"
                        class="rounded text-sm font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                    See all →
                </button>
            </div>
        </div>
    @endif

    {{-- Completed card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent completed</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ $completedTotal ?? count($completedRows) }}</p>
            </div>
            <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">{{ number_format($stats['jobs_past_hour']) }}/hr</span>
        </div>
        @if(count($completedPreview ?? []) === 0)
            <p class="py-2 text-sm text-gray-500 dark:text-gray-300">No completed jobs yet.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($completedPreview as $row)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'completed', 'item' => $row])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <button type="button"
                    x-on:click="setTab('completed')"
                    class="rounded text-sm font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ $completedTotal ?? count($completedRows) }} →
            </button>
        </div>
    </div>

    {{-- Failed card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-5 ring-1 dark:bg-gray-900 {{ count($failedRows) > 0 ? 'ring-red-600/15 dark:ring-red-400/30' : 'ring-gray-950/5 dark:ring-white/10' }}">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent failed</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ $failedTotal ?? count($failedRows) }}</p>
            </div>
            @if($stats['failed_past_hour'] > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                    <span class="size-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                    {{ number_format($stats['failed_past_hour']) }} past hr
                </span>
            @else
                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">none past hr</span>
            @endif
        </div>
        @if(count($failedPreview ?? []) === 0)
            <p class="py-2 text-sm text-gray-500 dark:text-gray-300">No failed jobs.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($failedPreview as $f)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'failed', 'item' => $f])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <button type="button"
                    x-on:click="setTab('failed')"
                    class="rounded text-sm font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ $failedTotal ?? count($failedRows) }} →
            </button>
        </div>
    </div>
</div>
