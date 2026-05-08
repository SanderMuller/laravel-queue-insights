@php
    /**
     * Overview pane — mission-grid summary cards. Each card lists a handful
     * of clickable preview rows that open the same modal as the full tab,
     * plus a "See all N →" button that switches tabs via the URL hash.
     *
     * Required scope vars:
     *   $queues, $atRisk
     *   $queuePreview                       — top-N queues for the Queues card
     *   $pendingEnabled, $hasPendingAny, $inFlightRows, $pendingRows, $delayedRows
     *   $pendingPreview                     — top-N pending rows for the Pending card
     *   $completedPreview, $failedPreview   — top-5 of the unsliced post-filter
     *                                          recent lists. Distinct from
     *                                          `$completedRows`/`$failedRows`
     *                                          which are paginated slices and
     *                                          would shift after the user
     *                                          navigates pages.
     *   $completedTotal, $failedTotal
     *   $stats, $totalDepth, $totalInFlight
     */
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    {{-- Queues card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 dark:bg-gray-900 {{ count($atRisk) > 0 ? 'ring-red-600/15 dark:ring-red-400/30' : 'ring-gray-950/5 dark:ring-white/10' }}">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Queues</h3>
                <p class="text-xs text-gray-500 dark:text-gray-300 tabular-nums">{{ count($queues) }}</p>
            </div>
            @if(count($atRisk) > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                    <span class="size-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                    {{ count($atRisk) }} need attention
                </span>
            @else
                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">all healthy</span>
            @endif
        </div>
        @if(count($queuePreview ?? []) === 0)
            <p class="py-2 text-xs text-gray-500 dark:text-gray-300">No queues configured.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($queuePreview as $q)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'queue', 'item' => $q])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <span class="text-[11px] tabular-nums text-gray-500 dark:text-gray-300">{{ number_format($totalDepth) }} backlog · {{ number_format($totalInFlight) }} in-flight</span>
            <button type="button"
                    x-on:click="window.location.hash = '#qi-queues'"
                    class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ count($queues) }} →
            </button>
        </div>
    </div>

    {{-- Pending card --}}
    @if($pendingEnabled)
        <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 dark:bg-gray-900 {{ count($inFlightRows) > 0 ? 'ring-amber-600/15 dark:ring-amber-400/30' : 'ring-gray-950/5 dark:ring-white/10' }}">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Pending</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-300 tabular-nums">{{ count($inFlightRows) + count($pendingRows) + count($delayedRows) }}</p>
                </div>
                @if(count($inFlightRows) > 0)
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                        <span class="size-1.5 animate-pulse rounded-full bg-amber-500 dark:bg-amber-400"></span>
                        {{ count($inFlightRows) }} in-flight
                    </span>
                @endif
            </div>
            @if(! $hasPendingAny)
                <p class="py-2 text-xs text-gray-500 dark:text-gray-300">No pending jobs tracked.</p>
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
                        x-on:click="window.location.hash = '#qi-pending'"
                        class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                    See all →
                </button>
            </div>
        </div>
    @endif

    {{-- Completed card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent completed</h3>
                <p class="text-xs text-gray-500 dark:text-gray-300 tabular-nums">{{ $completedTotal ?? count($completedRows) }}</p>
            </div>
            <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">{{ number_format($stats['jobs_past_hour']) }}/hr</span>
        </div>
        @if(count($completedPreview ?? []) === 0)
            <p class="py-2 text-xs text-gray-500 dark:text-gray-300">No completed jobs yet.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($completedPreview as $row)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'completed', 'item' => $row])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <button type="button"
                    x-on:click="window.location.hash = '#qi-completed'"
                    class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ $completedTotal ?? count($completedRows) }} →
            </button>
        </div>
    </div>

    {{-- Failed card --}}
    <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 dark:bg-gray-900 {{ count($failedRows) > 0 ? 'ring-red-600/15 dark:ring-red-400/30' : 'ring-gray-950/5 dark:ring-white/10' }}">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent failed</h3>
                <p class="text-xs text-gray-500 dark:text-gray-300 tabular-nums">{{ $failedTotal ?? count($failedRows) }}</p>
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
            <p class="py-2 text-xs text-gray-500 dark:text-gray-300">No failed jobs.</p>
        @else
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($failedPreview as $f)
                    @include('queue-insights::partials.card-mini-row', ['type' => 'failed', 'item' => $f])
                @endforeach
            </ul>
        @endif
        <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2 dark:border-white/10">
            <button type="button"
                    x-on:click="window.location.hash = '#qi-failed'"
                    class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-emerald-300">
                See all {{ $failedTotal ?? count($failedRows) }} →
            </button>
        </div>
    </div>
</div>
