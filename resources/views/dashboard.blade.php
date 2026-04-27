<div wire:poll.10s class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when the details modal is open so AT
        users don't hear the background dashboard and keyboard focus can't escape the
        modal. MUST be a sibling of the modal (not an ancestor), or the modal itself
        would be inerted. See Resolved Q #13 + #16. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-8"
         x-data x-bind:inert="@js($hasOpenModal)">

        <x-queue-insights::flash-banner/>

        @php
            $totalDepth = array_sum(array_map(fn ($q): int => is_numeric($q['depth']) ? (int) $q['depth'] : 0, $queues));
            $totalInFlight = array_sum(array_map(fn ($q): int => is_numeric($q['inflight'] ?? null) ? (int) $q['inflight'] : 0, $queues));
            $atRisk = array_values(array_filter($queues, fn ($q) => $q['error'] || $q['stale']));
            $healthy = array_values(array_filter($queues, fn ($q) => ! $q['error'] && ! $q['stale']));
            $sortedQueues = array_merge($atRisk, $healthy);

            $fmtMs = static function (?int $ms): string {
                if ($ms === null) return '—';
                if ($ms < 1000) return number_format($ms).'ms';
                if ($ms < 60_000) return number_format($ms / 1000, 1).'s';
                return number_format($ms / 60_000, 1).'m';
            };
            $statTiles = [
                ['label' => 'Jobs / min', 'value' => number_format($stats['jobs_per_minute']), 'sub' => null, 'tone' => 'neutral'],
                ['label' => 'Jobs past hour', 'value' => number_format($stats['jobs_past_hour']), 'sub' => null, 'tone' => 'neutral'],
                ['label' => 'Failed past hour', 'value' => number_format($stats['failed_past_hour']), 'sub' => null, 'tone' => $stats['failed_past_hour'] > 0 ? 'danger' : 'neutral'],
                ['label' => 'Max throughput', 'value' => number_format($stats['max_throughput_hour']), 'sub' => '/hr', 'tone' => 'neutral'],
                ['label' => 'Max wait', 'value' => $fmtMs($stats['max_wait_ms']), 'sub' => 'p95', 'tone' => $stats['max_wait_ms'] !== null && $stats['max_wait_ms'] > 5_000 ? 'warn' : 'neutral'],
                ['label' => 'Max runtime', 'value' => $fmtMs($stats['max_runtime_ms']), 'sub' => 'p95', 'tone' => 'neutral'],
            ];
        @endphp

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-queue-insights::throughput-sparkline :throughput="$throughput"/>
            </div>
            <dl aria-label="Headline stats" class="grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl bg-white p-5 ring-1 ring-gray-950/5">
                @foreach($statTiles as $tile)
                    @include('queue-insights::partials.stat-tile', $tile)
                @endforeach
            </dl>
        </div>

        @if($batchesEnabled)
            @php
                $activeBatchCount = count(array_filter($batches, fn ($b) => ! ($b['finished_at'] instanceof \Carbon\CarbonInterface) && ! ($b['cancelled_at'] instanceof \Carbon\CarbonInterface)));
            @endphp
            <section id="qi-batches-section" aria-label="Batches">
                <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <div class="flex items-center gap-2">
                        <svg class="size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
                        </svg>
                        <h2 class="text-lg font-semibold tracking-tight text-gray-900">Batches</h2>
                    </div>
                    <p class="text-sm text-gray-500 tabular-nums">{{ $activeBatchCount }} active · {{ count($batches) }} tracked</p>
                </div>

                @if(count($batches) === 0)
                    <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                        No active batches.
                    </div>
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
            </section>
        @endif

        <section>
            <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
                <div class="flex items-center gap-2">
                    <svg class="size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75ZM2 10a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 15.25Z"/>
                    </svg>
                    <h2 class="text-lg font-semibold tracking-tight text-gray-900">Queues</h2>
                </div>
                <p class="text-sm text-gray-500 tabular-nums">
                    {{ count($queues) }} configured · {{ number_format($totalDepth) }} backlog · {{ number_format($totalInFlight) }} in-flight
                </p>
            </div>

            @if(count($queues) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    No queues configured. Add entries to <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">config/queue-insights.php</code> under <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">snapshots</code>.
                </div>
            @else
                @if(count($atRisk) > 0)
                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-red-700">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
                        </svg>
                        <x-queue-insights::hint triggerClass="inline-flex items-center gap-1 cursor-help underline decoration-dotted decoration-red-300 underline-offset-2">
                            <span>Needs attention</span>
                            <span class="font-normal text-red-500">({{ count($atRisk) }})</span>
                            <x-slot:tip>
                                Queues whose last snapshot is older than 120s, missing entirely, or whose snapshot command errored.
                                The scheduled <code class="rounded bg-white/10 px-1 font-mono">queue-insights:snapshot</code> task (every minute) writes these &mdash; confirm Laravel's scheduler is running and the Redis connection is reachable.
                            </x-slot:tip>
                        </x-queue-insights::hint>
                    </h3>
                    <div class="mb-5 rounded-lg bg-white ring-1 ring-red-600/20">
                        <div class="grid grid-cols-12 items-center gap-4 border-b border-red-200/60 px-4 py-2 text-xs font-medium text-red-700/80">
                            <div class="col-span-4">Queue</div>
                            <div class="col-span-4 grid grid-cols-3 text-center">
                                <div>Depth</div>
                                <div>In-flight</div>
                                <div>Delayed</div>
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
                        <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500">Healthy <span class="font-normal text-gray-400">({{ count($healthy) }})</span></h3>
                    @endif
                    <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                        <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                            <div class="col-span-4">Queue</div>
                            <div class="col-span-4 grid grid-cols-3 text-center">
                                <div>Depth</div>
                                <div>In-flight</div>
                                <div>Delayed</div>
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
        </section>

        {{-- Pending jobs across all configured queues. Drawn from the
            `pending-zset:{conn}:{queue}` Redis tracking written by
            RecordJobQueued — driver-agnostic, oldest-first. Hidden when
            pending tracking is disabled (no zset writes = nothing to show). --}}
        @if($pendingEnabled)
        <section aria-label="Pending jobs">
            <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                <div class="flex items-center gap-2">
                    <svg class="size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                    </svg>
                    <h2 class="text-lg font-semibold tracking-tight text-gray-900">Pending</h2>
                </div>
                <p class="text-sm text-gray-500 tabular-nums">{{ count($inFlightRows) }} in-flight · {{ count($pendingRows) }} pending · {{ count($delayedRows) }} delayed</p>
                <x-queue-insights::hint placement="bottom" triggerClass="cursor-help text-xs text-gray-400 underline decoration-dotted decoration-gray-300 underline-offset-2">
                    what is this?
                    <x-slot:tip>
                        Jobs the package is currently tracking. <strong>In-flight</strong> = a worker has picked it up and is processing (longest-running first). <strong>Pending now</strong> = waiting in line, runnable now (oldest first). <strong>Delayed</strong> = scheduled for a future <code class="rounded bg-white/10 px-1 font-mono">available_at</code> (soonest first). Each group is capped at 50, aggregated across every connection / queue listed in <code class="rounded bg-white/10 px-1 font-mono">queue-insights.snapshots</code>.
                    </x-slot:tip>
                </x-queue-insights::hint>
            </div>

            @if(count($inFlightRows) === 0 && count($pendingRows) === 0 && count($delayedRows) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    No pending jobs tracked.
                </div>
            @else
                @if(count($inFlightRows) > 0)
                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-amber-700">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd"/>
                        </svg>
                        In-flight <span class="font-normal text-amber-500">({{ count($inFlightRows) }})</span>
                    </h3>
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
                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500">
                        Pending now <span class="font-normal text-gray-400">({{ count($pendingRows) }})</span>
                    </h3>
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
                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-indigo-700">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                        </svg>
                        Delayed <span class="font-normal text-indigo-500">({{ count($delayedRows) }})</span>
                    </h3>
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
        </section>
        @endif

        {{-- Recent completed --}}
        <section>
            <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                <div class="flex items-center gap-2">
                    <svg class="size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/>
                    </svg>
                    <h2 class="text-lg font-semibold tracking-tight text-gray-900">Recent completed</h2>
                </div>
                @if($completedFiltersActive)
                    <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">filtered</span>
                @endif
                <p class="text-sm text-gray-500 tabular-nums">{{ count($completedRows) }} jobs</p>
            </div>

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
                </div>
            @endif
        </section>

        {{-- Recent failed --}}
        <section>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <svg class="size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
                </svg>
                <h2 class="text-lg font-semibold tracking-tight text-gray-900">Recent failed</h2>
                @if($failedFiltersActive)
                    <span class="rounded bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">filtered</span>
                @endif

                {{-- Bulk Retry — visible only when the host defines retryFailedJobs,
                    user has the gate, filters are active, AND the matching set fits
                    inside the 100-row cap. Server enforces all three rules in
                    retryFailedBulk() regardless of UI state. Two-click confirm
                    (Alpine state — first click flips label, second click fires). --}}
                @if($canRetry && $failedFiltersActive && $bulkRetryCount !== null && $bulkRetryCount > 0)
                    <div class="ml-auto" x-data="{ confirming: false, t: null }"
                         x-on:click.outside="confirming = false">
                        @if($bulkRetryCount > 100)
                            <span class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10"
                                  title="Bulk retry rejects sets larger than 100 — narrow the filter.">
                                {{ $bulkRetryCount }} matches · narrow to retry
                            </span>
                        @else
                            <button type="button"
                                    x-bind:class="confirming
                                        ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500'
                                        : 'bg-white text-emerald-700 ring-emerald-600/30 hover:bg-emerald-50'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                                    x-on:click="
                                        if (! confirming) {
                                            confirming = true;
                                            t = setTimeout(() => confirming = false, 2500);
                                            return;
                                        }
                                        clearTimeout(t);
                                        confirming = false;
                                        $wire.retryFailedBulk();
                                    ">
                                <span x-show="! confirming">Retry {{ $bulkRetryCount }} job{{ $bulkRetryCount === 1 ? '' : 's' }}</span>
                                <span x-show="confirming" x-cloak>Confirm retry?</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>

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
                </div>
            @endif
        </section>

    </div>{{-- /#qi-dashboard-content --}}

    {{-- Modal stacking order: batch modal renders FIRST so item modals
        (details / failed / pending), declared after, sit visually on top
        when both are open. Opening an item from the batch modal preserves
        `expandedBatchId` (see QueueInsightsDashboard::openPayload et al.)
        so closing the item modal restores the batch view underneath —
        same back-and-forth pattern the chain detail uses inside a single
        modal, generalised across modals. --}}

    {{-- Batch detail modal — opens when the user clicks a batch row in the
        Batches section, or via openBatch() from an item modal's batch
        chip. Mounted by `expandedBatchId !== ''` (empty string = closed). --}}
    @if($batchesEnabled && $expandedBatchId !== '')
        <x-queue-insights::batch-modal :batch="$selectedBatch"/>
    @endif

    {{-- Details modal (completed jobs) --}}
    @if($selectedPayload !== null)
        <x-queue-insights::details-modal
            :payload="$selectedPayload"
            :payload-tab="$payloadTab"
            :capture-mode="$captureMode"
            :expanded-batch-id="$expandedBatchId"/>
    @endif

    {{-- Failed-job detail modal --}}
    @if($selectedFailed !== null)
        <x-queue-insights::failed-modal :failed="$selectedFailed" :can-retry="$canRetry" :expanded-batch-id="$expandedBatchId"/>
    @endif

    {{-- Pending-job modal — opens whenever the user clicks a row in the
        Pending or Delayed sub-group. Re-fetches the row each poll so a
        worker grabbing the job mid-modal flips the modal into its
        "no longer pending" empty state. Mounted by `selectedPendingUuid`,
        not by `$selectedPending` — that way clicking → opening → race
        still mounts the modal so the user sees the empty-state, instead
        of a confusing silent no-op. --}}
    @if($pendingEnabled && $selectedPendingUuid !== null)
        <x-queue-insights::pending-modal :pending="$selectedPending" :expanded-batch-id="$expandedBatchId"/>
    @endif
</div>

