@php
    /**
     * Tabbed dashboard workspace. Six tabs:
     *
     *   Overview (default)  — mission-grid summary cards. Each card shows a
     *                          handful of clickable preview rows that open the
     *                          same modal as the full tab, plus a "See all N →"
     *                          button that switches to the matching tab.
     *   Queues              — at-risk + healthy tables.
     *   Pending             — in-flight + pending-now + delayed tables.
     *   Batches             — batch list (only when batches enabled).
     *   Completed           — full completed-jobs list with filters.
     *   Failed              — full failed-jobs list with filters + bulk-retry.
     *
     * Tab persists in `window.location.hash` (`#qi-overview`, `#qi-queues`, …)
     * so refreshes and bookmarks land back where the user left off, and the
     * mission-grid card buttons just set the hash to drive tab changes.
     *
     * Required scope vars (inherited from including dashboard):
     *   $queues, $atRisk, $healthy, $sortedQueues
     *   $queuePreview                         — top-N for the Queues card
     *   $batches, $batchesEnabled, $activeBatchCount
     *   $pendingEnabled, $inFlightRows, $pendingRows, $delayedRows
     *   $pendingPreview                       — top-N for the Pending card
     *   $completedRows, $failedRows
     *   $stats, $bulkRetryCount, $canRetry
     *   $totalDepth, $totalInFlight, $hasPendingAny
     *   $completedFiltersActive, $failedFiltersActive
     *   $filterConnectionOptions, $filterQueueOptions, $filterClassOptions
     */
@endphp

<div x-data="{ tab: 'overview' }"
     x-init="
        const apply = () => {
            const m = (window.location.hash || '').match(/^#qi-(overview|queues|pending|batches|completed|failed)$/);
            if (m) tab = m[1];
        };
        apply();
        window.addEventListener('hashchange', apply);
     "
     class="flex flex-col gap-4">

    {{-- Sticky tab strip — bleeds into the page padding so the underline runs full-width. --}}
    <div class="sticky top-0 z-10 -mx-6 border-b border-gray-950/5 bg-gray-50/90 px-6 backdrop-blur sm:-mx-8 sm:px-8 lg:-mx-10 lg:px-10">
        <nav class="-mb-px flex flex-wrap items-center gap-x-1" aria-label="Sections">
            <button type="button"
                    x-on:click="tab='overview'; history.replaceState(null,'','#qi-overview')"
                    x-bind:class="tab==='overview' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                    class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium">
                Overview
            </button>

            {{-- Every tab badge follows the same rule: total number of items
                in that tab. No urgency variants, no in-flight callouts —
                operators read it the same way for every tab. Urgency is
                surfaced in the Overview pane cards (ring colours, status
                pills) and inside each tab's content. --}}
            <button type="button"
                    x-on:click="tab='queues'; history.replaceState(null,'','#qi-queues')"
                    x-bind:class="tab==='queues' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                    class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
                Queues
                <span class="text-xs font-normal text-gray-400">{{ count($queues) }}</span>
            </button>

            @if($pendingEnabled)
                <button type="button"
                        x-on:click="tab='pending'; history.replaceState(null,'','#qi-pending')"
                        x-bind:class="tab==='pending' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                        class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
                    Pending
                    <span class="text-xs font-normal text-gray-400">{{ count($inFlightRows) + count($pendingRows) + count($delayedRows) }}</span>
                </button>
            @endif

            @if($batchesEnabled)
                <button type="button"
                        x-on:click="tab='batches'; history.replaceState(null,'','#qi-batches')"
                        x-bind:class="tab==='batches' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                        class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
                    Batches
                    <span class="text-xs font-normal text-gray-400">{{ count($batches) }}</span>
                </button>
            @endif

            <button type="button"
                    x-on:click="tab='completed'; history.replaceState(null,'','#qi-completed')"
                    x-bind:class="tab==='completed' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                    class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
                Completed
                <span class="text-xs font-normal text-gray-400">{{ $completedTotal ?? count($completedRows) }}</span>
            </button>

            <button type="button"
                    x-on:click="tab='failed'; history.replaceState(null,'','#qi-failed')"
                    x-bind:class="tab==='failed' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900'"
                    class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
                Failed
                <span class="text-xs font-normal text-gray-400">{{ $failedTotal ?? count($failedRows) }}</span>
            </button>
        </nav>
    </div>

    {{-- ============== Overview pane (mission grid) ============== --}}
    <div x-show="tab==='overview'" x-cloak>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {{-- Queues card --}}
            <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 {{ count($atRisk) > 0 ? 'ring-red-600/15' : 'ring-gray-950/5' }}">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-900">Queues</h3>
                        <p class="text-xs text-gray-500 tabular-nums">{{ count($queues) }}</p>
                    </div>
                    @if(count($atRisk) > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">
                            <span class="size-1.5 rounded-full bg-red-500"></span>
                            {{ count($atRisk) }} need attention
                        </span>
                    @else
                        <span class="text-[10px] font-medium text-emerald-700">all healthy</span>
                    @endif
                </div>
                @if(count($queuePreview ?? []) === 0)
                    <p class="py-2 text-xs text-gray-500">No queues configured.</p>
                @else
                    <ul role="list" class="divide-y divide-gray-950/5">
                        @foreach($queuePreview as $q)
                            @include('queue-insights::partials.card-mini-row', ['type' => 'queue', 'item' => $q])
                        @endforeach
                    </ul>
                @endif
                <div class="mt-auto flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2">
                    <span class="text-[11px] tabular-nums text-gray-500">{{ number_format($totalDepth) }} backlog · {{ number_format($totalInFlight) }} in-flight</span>
                    <button type="button"
                            x-on:click="window.location.hash = '#qi-queues'"
                            class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        See all {{ count($queues) }} →
                    </button>
                </div>
            </div>

            {{-- Pending card --}}
            @if($pendingEnabled)
                <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 {{ count($inFlightRows) > 0 ? 'ring-amber-600/15' : 'ring-gray-950/5' }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-baseline gap-2">
                            <h3 class="text-sm font-semibold tracking-tight text-gray-900">Pending</h3>
                            <p class="text-xs text-gray-500 tabular-nums">{{ count($inFlightRows) + count($pendingRows) + count($delayedRows) }}</p>
                        </div>
                        @if(count($inFlightRows) > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                <span class="size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                {{ count($inFlightRows) }} in-flight
                            </span>
                        @endif
                    </div>
                    @if(! $hasPendingAny)
                        <p class="py-2 text-xs text-gray-500">No pending jobs tracked.</p>
                    @else
                        <ul role="list" class="divide-y divide-gray-950/5">
                            @foreach($pendingPreview as $p)
                                @include('queue-insights::partials.card-mini-row', ['type' => 'pending', 'item' => $p])
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2">
                        <span class="text-[11px] tabular-nums text-gray-500">
                            {{ count($inFlightRows) }} in-flight · {{ count($pendingRows) }} pending · {{ count($delayedRows) }} delayed
                        </span>
                        <button type="button"
                                x-on:click="window.location.hash = '#qi-pending'"
                                class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                            See all →
                        </button>
                    </div>
                </div>
            @endif

            {{-- Completed card --}}
            <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 ring-gray-950/5">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-900">Recent completed</h3>
                        <p class="text-xs text-gray-500 tabular-nums">{{ $completedTotal ?? count($completedRows) }}</p>
                    </div>
                    <span class="text-[10px] font-medium text-emerald-700">{{ number_format($stats['jobs_past_hour']) }}/hr</span>
                </div>
                @if(count($completedRows) === 0)
                    <p class="py-2 text-xs text-gray-500">No completed jobs yet.</p>
                @else
                    <ul role="list" class="divide-y divide-gray-950/5">
                        @foreach(array_slice($completedRows, 0, 5) as $row)
                            @include('queue-insights::partials.card-mini-row', ['type' => 'completed', 'item' => $row])
                        @endforeach
                    </ul>
                @endif
                <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2">
                    <button type="button"
                            x-on:click="window.location.hash = '#qi-completed'"
                            class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        See all {{ $completedTotal ?? count($completedRows) }} →
                    </button>
                </div>
            </div>

            {{-- Failed card --}}
            <div class="flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 {{ count($failedRows) > 0 ? 'ring-red-600/15' : 'ring-gray-950/5' }}">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-900">Recent failed</h3>
                        <p class="text-xs text-gray-500 tabular-nums">{{ $failedTotal ?? count($failedRows) }}</p>
                    </div>
                    @if($stats['failed_past_hour'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">
                            <span class="size-1.5 rounded-full bg-red-500"></span>
                            {{ number_format($stats['failed_past_hour']) }} past hr
                        </span>
                    @else
                        <span class="text-[10px] font-medium text-emerald-700">none past hr</span>
                    @endif
                </div>
                @if(count($failedRows) === 0)
                    <p class="py-2 text-xs text-gray-500">No failed jobs.</p>
                @else
                    <ul role="list" class="divide-y divide-gray-950/5">
                        @foreach(array_slice($failedRows, 0, 5) as $f)
                            @include('queue-insights::partials.card-mini-row', ['type' => 'failed', 'item' => $f])
                        @endforeach
                    </ul>
                @endif
                <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-950/5 pt-2">
                    <button type="button"
                            x-on:click="window.location.hash = '#qi-failed'"
                            class="rounded text-xs font-medium text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        See all {{ $failedTotal ?? count($failedRows) }} →
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============== Queues pane ============== --}}
    <div x-show="tab==='queues'" x-cloak>
        @if(count($queues) === 0)
            <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                No queues configured. Add entries to <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">config/queue-insights.php</code> under <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">snapshots</code>.
            </div>
        @else
            @if(count($atRisk) > 0)
                <h3 class="mb-2 text-xs font-semibold tracking-wide text-red-700">Needs attention <span class="font-normal text-red-500 tabular-nums">({{ count($atRisk) }})</span></h3>
                <div class="mb-5 rounded-lg bg-white ring-1 ring-red-600/20">
                    <div class="grid grid-cols-12 items-center gap-4 border-b border-red-200/60 px-4 py-2 text-xs font-medium text-red-700/80">
                        <div class="col-span-4">Queue</div>
                        <div class="col-span-4 grid grid-cols-3 text-center">
                            <div>Depth</div><div>In-flight</div><div>Delayed</div>
                        </div>
                        <div class="col-span-2 text-right">Wait</div>
                        <div class="col-span-2 text-right">Status</div>
                    </div>
                    <ul role="list" class="divide-y divide-red-200/60">
                        @foreach($atRisk as $q)
                            @include('queue-insights::partials.queue-row', ['q' => $q])
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(count($healthy) > 0)
                @if(count($atRisk) > 0)
                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500">Healthy <span class="font-normal text-gray-400 tabular-nums">({{ count($healthy) }})</span></h3>
                @endif
                <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                    <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                        <div class="col-span-4">Queue</div>
                        <div class="col-span-4 grid grid-cols-3 text-center">
                            <div>Depth</div><div>In-flight</div><div>Delayed</div>
                        </div>
                        <div class="col-span-2 text-right">Wait</div>
                        <div class="col-span-2 text-right">Status</div>
                    </div>
                    <ul role="list" class="divide-y divide-gray-950/5">
                        @foreach($healthy as $q)
                            @include('queue-insights::partials.queue-row', ['q' => $q])
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>

    {{-- ============== Pending pane ============== --}}
    @if($pendingEnabled)
        <div x-show="tab==='pending'" x-cloak>
            @if(! $hasPendingAny)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">No pending jobs tracked.</div>
            @else
                @if(count($inFlightRows) > 0)
                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-amber-700">In-flight <span class="font-normal text-amber-500 tabular-nums">({{ count($inFlightRows) }})</span></h3>
                    <div class="mb-5 rounded-lg bg-white ring-1 ring-amber-600/15">
                        <div class="grid grid-cols-12 items-center gap-4 border-b border-amber-200/60 px-4 py-2 text-xs font-medium text-amber-700/80">
                            <div class="col-span-5">Job</div>
                            <div class="col-span-3">Queue</div>
                            <div class="col-span-2 text-right">Queued</div>
                            <div class="col-span-2 text-right">Started</div>
                        </div>
                        <ul role="list" class="divide-y divide-amber-200/60">
                            @foreach($inFlightRows as $row)
                                @include('queue-insights::partials.pending-row', ['row' => $row, 'isInFlight' => true, 'isDelayed' => false])
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(count($pendingRows) > 0)
                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500">Pending now <span class="font-normal text-gray-400 tabular-nums">({{ count($pendingRows) }})</span></h3>
                    <div class="mb-5 rounded-lg bg-white ring-1 ring-gray-950/5">
                        <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                            <div class="col-span-5">Job</div>
                            <div class="col-span-3">Queue</div>
                            <div class="col-span-2 text-right">Queued</div>
                            <div class="col-span-2 text-right">Available</div>
                        </div>
                        <ul role="list" class="divide-y divide-gray-950/5">
                            @foreach($pendingRows as $row)
                                @include('queue-insights::partials.pending-row', ['row' => $row, 'isDelayed' => false])
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(count($delayedRows) > 0)
                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-indigo-700">Delayed <span class="font-normal text-indigo-500 tabular-nums">({{ count($delayedRows) }})</span></h3>
                    <div class="rounded-lg bg-white ring-1 ring-indigo-600/15">
                        <div class="grid grid-cols-12 items-center gap-4 border-b border-indigo-200/60 px-4 py-2 text-xs font-medium text-indigo-700/80">
                            <div class="col-span-5">Job</div>
                            <div class="col-span-3">Queue</div>
                            <div class="col-span-2 text-right">Queued</div>
                            <div class="col-span-2 text-right">Runs</div>
                        </div>
                        <ul role="list" class="divide-y divide-indigo-200/60">
                            @foreach($delayedRows as $row)
                                @include('queue-insights::partials.pending-row', ['row' => $row, 'isDelayed' => true])
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    @endif

    {{-- ============== Batches pane ============== --}}
    @if($batchesEnabled)
        <div id="qi-batches-section" x-show="tab==='batches'" x-cloak>
            @if(count($batches) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">No active batches.</div>
            @else
                <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                    <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                        <div class="col-span-5">Batch</div>
                        <div class="col-span-3">Progress</div>
                        <div class="col-span-2 text-center">Counts</div>
                        <div class="col-span-2 text-right">Status</div>
                    </div>
                    <ul role="list" class="divide-y divide-gray-950/5">
                        @foreach($batches as $batch)
                            @include('queue-insights::partials.batch-row', ['batch' => $batch])
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- ============== Completed pane ============== --}}
    <div x-show="tab==='completed'" x-cloak>
        @if($completedFiltersActive)
            <div class="mb-3">
                <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">filtered</span>
            </div>
        @endif

        @include('queue-insights::partials.filter-form', [
            'active' => $completedFiltersActive,
            'models' => [
                'connection' => 'completedFilterConnection',
                'queue' => 'completedFilterQueue',
                'class' => 'selectedClass',
                'from' => 'completedFilterFrom',
                'to' => 'completedFilterTo',
            ],
            'clearMethod' => 'clearCompletedFilters',
            'connectionOptions' => $filterConnectionOptions,
            'queueOptions' => $filterQueueOptions,
            'classOptions' => $filterClassOptions,
        ])

        @if(count($completedRows) === 0)
            <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                @if($completedFiltersActive)
                    No completed jobs match the current filters.
                @else
                    No completed jobs recorded yet.
                @endif
            </div>
        @else
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                    <div class="col-span-4">Job</div>
                    <div class="col-span-3">Queue</div>
                    <div class="col-span-2 text-right">Runtime</div>
                    <div class="col-span-2 text-right">Completed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5">
                    @foreach($completedRows as $row)
                        @include('queue-insights::partials.completed-row', ['row' => $row])
                    @endforeach
                </ul>
                @include('queue-insights::partials.pagination-controls', [
                    'page' => $completedPage ?? 1,
                    'totalPages' => $completedTotalPages ?? 1,
                    'total' => $completedTotal ?? count($completedRows),
                    'perPage' => $completedPerPage ?? count($completedRows),
                    'gotoMethod' => 'gotoCompletedPage',
                ])
            </div>
        @endif
    </div>

    {{-- ============== Failed pane ============== --}}
    <div x-show="tab==='failed'" x-cloak>
        @if($failedFiltersActive)
            <div class="mb-3">
                <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">filtered</span>
            </div>
        @endif

        @if($canRetry && $failedFiltersActive && $bulkRetryCount !== null && $bulkRetryCount > 0)
            <div class="mb-3 flex justify-end" x-data="{ confirming: false, t: null }"
                 x-on:click.outside="confirming = false">
                @if($bulkRetryCount > 100)
                    <span class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10"
                          title="Bulk retry rejects sets larger than 100 — narrow the filter.">
                        {{ $bulkRetryCount }} matches · narrow to retry
                    </span>
                @else
                    <button type="button"
                            x-bind:class="confirming ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500' : 'bg-white text-emerald-700 ring-emerald-600/30 hover:bg-emerald-50'"
                            class="rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                            x-on:click="
                                if (! confirming) { confirming = true; t = setTimeout(() => confirming = false, 2500); return; }
                                clearTimeout(t); confirming = false; $wire.retryFailedBulk();
                            ">
                        <span x-show="! confirming">Retry {{ $bulkRetryCount }} job{{ $bulkRetryCount === 1 ? '' : 's' }}</span>
                        <span x-show="confirming" x-cloak>Confirm retry?</span>
                    </button>
                @endif
            </div>
        @endif

        @include('queue-insights::partials.filter-form', [
            'active' => $failedFiltersActive,
            'models' => [
                'connection' => 'filterConnection',
                'queue' => 'filterQueue',
                'class' => 'filterClass',
                'from' => 'filterFrom',
                'to' => 'filterTo',
            ],
            'clearMethod' => 'clearFailedFilters',
            'connectionOptions' => $filterConnectionOptions,
            'queueOptions' => $filterQueueOptions,
            'classOptions' => $filterClassOptions,
        ])

        @if(count($failedRows) === 0)
            <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                @if($failedFiltersActive)
                    No failed jobs match the current filters.
                @else
                    No failed jobs recorded.
                @endif
            </div>
        @else
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                    <div class="col-span-1"></div>
                    <div class="col-span-5">Job</div>
                    <div class="col-span-3">Queue</div>
                    <div class="col-span-2 text-right">Failed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5">
                    @foreach ($failedRows as $f)
                        @include('queue-insights::partials.failed-list-row', ['f' => $f])
                    @endforeach
                </ul>
                @include('queue-insights::partials.pagination-controls', [
                    'page' => $failedPage ?? 1,
                    'totalPages' => $failedTotalPages ?? 1,
                    'total' => $failedTotal ?? count($failedRows),
                    'perPage' => $failedPerPage ?? count($failedRows),
                    'gotoMethod' => 'gotoFailedPage',
                ])
            </div>
        @endif
    </div>

</div>
