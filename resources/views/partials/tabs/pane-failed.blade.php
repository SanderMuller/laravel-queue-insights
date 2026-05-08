@php
    /**
     * Failed pane — filter form + bulk-retry control + paged list of
     * failed jobs. The bulk-retry button only renders when the host has
     * defined `retryFailedJobs` AND a filter is active AND the matched
     * set is non-empty; the server enforces the same rules in
     * QueueInsightsDashboard::retryFailedBulk regardless of UI state.
     *
     * Required scope vars:
     *   $failedRows, $failedFiltersActive, $bulkRetryCount, $canRetry
     *   $failedPage, $failedTotalPages, $failedTotal, $failedPerPage
     *   $filterConnectionOptions, $filterQueueOptions, $filterClassOptions
     */
@endphp
@if($failedFiltersActive)
    <div class="mb-3">
        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-400/30">filtered</span>
    </div>
@endif

@if($canRetry && $failedFiltersActive && $bulkRetryCount !== null && $bulkRetryCount > 0)
    <div class="mb-3 flex justify-end" x-data="{ confirming: false, t: null }"
         x-on:click.outside="confirming = false">
        @if($bulkRetryCount > 100)
            <span class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10"
                  title="Bulk retry rejects sets larger than 100 — narrow the filter.">
                {{ $bulkRetryCount }} matches · narrow to retry
            </span>
        @else
            <button type="button"
                    x-bind:class="confirming ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500 dark:bg-red-500 dark:ring-red-400' : 'bg-white text-emerald-700 ring-emerald-600/30 hover:bg-emerald-50 dark:bg-gray-900 dark:text-emerald-300 dark:ring-emerald-400/30 dark:hover:bg-emerald-900/40'"
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
    // wire:model property name for the "Show silenced" toggle on the
    // failed pane. Distinct from the completed-pane toggle
    // (`completedIncludeSilenced`) so an operator can dig into silenced
    // failures without unmuting silenced successes (or vice versa).
    'silenceModel' => 'includeSilenced',
])

@if(count($failedRows) === 0)
    <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
        @if($failedFiltersActive)
            No failed jobs match the current filters.
        @else
            No failed jobs recorded.
        @endif
    </div>
@else
    <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
            <div class="col-span-1"></div>
            <div class="col-span-5">Job</div>
            <div class="col-span-3">Queue</div>
            <div class="col-span-2 text-right">Failed</div>
            <div class="col-span-1"></div>
        </div>
        <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach($failedRows as $f)
                @include('queue-insights::partials.failed-list-row', ['f' => $f])
            @endforeach
        </ul>
        {{-- Pass BOTH the new paginator + the legacy scalar locals — see
             pane-completed.blade.php for the rationale. Drop the legacy
             locals one release after 0.11. --}}
        @include('queue-insights::partials.pagination-controls', [
            'paginator' => $failedPaginator,
            'gotoMethod' => 'gotoFailedPage',
            'perPageModel' => 'failedPerPage',
            'perPageOptions' => $perPageOptions,
            'page' => $failedPage ?? 1,
            'totalPages' => $failedTotalPages ?? 1,
            'total' => $failedTotal ?? count($failedRows),
            'perPage' => $failedPerPage ?? count($failedRows),
        ])
    </div>
@endif
