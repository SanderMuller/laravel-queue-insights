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
            <div class="flex items-center gap-2.5">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 4.25A2.25 2.25 0 0 1 4.25 2h2.5A2.25 2.25 0 0 1 9 4.25v2.5A2.25 2.25 0 0 1 6.75 9h-2.5A2.25 2.25 0 0 1 2 6.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 2h2.5A2.25 2.25 0 0 1 18 4.25v2.5A2.25 2.25 0 0 1 15.75 9h-2.5A2.25 2.25 0 0 1 11 6.75v-2.5Zm-9 9A2.25 2.25 0 0 1 4.25 11h2.5A2.25 2.25 0 0 1 9 13.25v2.5A2.25 2.25 0 0 1 6.75 18h-2.5A2.25 2.25 0 0 1 2 15.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 11h2.5A2.25 2.25 0 0 1 18 13.25v2.5A2.25 2.25 0 0 1 15.75 18h-2.5A2.25 2.25 0 0 1 11 15.75v-2.5Z"/></svg>
                </span>
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Classes</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ count($classes) }}</p>
                </div>
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
                <div class="flex items-center gap-2.5">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .27.144.518.378.651l3 1.714a.75.75 0 0 0 .744-1.302L10.75 9.566V5Z" clip-rule="evenodd"/></svg>
                    </span>
                    <div class="flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Pending</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ count($inFlightRows) + count($pendingRows) + count($delayedRows) }}</p>
                    </div>
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
            <div class="flex items-center gap-2.5">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                </span>
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent completed</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ $completedTotal ?? count($completedRows) }}</p>
                </div>
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
            <div class="flex items-center gap-2.5">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                </span>
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent failed</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-300 tabular-nums">{{ $failedTotal ?? count($failedRows) }}</p>
                </div>
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
