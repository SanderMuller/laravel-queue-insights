@php
    /**
     * Completed pane — filter form + paged list of completed jobs.
     *
     * Required scope vars:
     *   $completedRows, $completedFiltersActive, $selectedClass
     *   $completedPage, $completedTotalPages, $completedTotal, $completedPerPage
     *   $filterConnectionOptions, $filterQueueOptions, $filterClassOptions
     */
    $amountCompletedRows = count($completedRows);
@endphp
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
    // "Show silenced" toggle (URL `?cs=1`) — independent from the failed-pane
    // toggle so operators can dig into silenced failures without unmuting
    // silenced successes (or vice versa).
    'silenceModel' => 'completedIncludeSilenced',
])

@if($amountCompletedRows === 0)
    <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
        @if($completedFiltersActive)
            No completed jobs match the current filters.
        @else
            No completed jobs recorded yet.
        @endif
    </div>
@else
    <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
            <div class="col-span-5">Job</div>
            <div class="col-span-2">Queue</div>
            <div class="col-span-2 text-right">Runtime</div>
            <div class="col-span-2 text-right">Completed</div>
            <div class="col-span-1"></div>
        </div>
        <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach($completedRows as $row)
                @include('queue-insights::partials.completed-row', ['row' => $row])
            @endforeach
        </ul>
        {{-- Pass BOTH the new paginator + the legacy scalar locals so a host
             that published `pagination-controls` on the pre-0.11 contract
             ($page / $totalPages / $total / $perPage / $gotoMethod) keeps
             rendering until they re-publish. The new partial reads
             `$paginator` and ignores the scalars; the old partial reads
             scalars and ignores the paginator. Drop the legacy locals one
             release after 0.11. --}}
        @include('queue-insights::partials.pagination-controls', [
            'paginator' => $completedPaginator,
            'gotoMethod' => 'gotoCompletedPage',
            'perPageModel' => 'completedPerPage',
            'perPageOptions' => $perPageOptions,
            'page' => $completedPage ?? 1,
            'totalPages' => $completedTotalPages ?? 1,
            'total' => $completedTotal ?? $amountCompletedRows,
            'perPage' => $completedPerPage ?? $amountCompletedRows,
        ])
    </div>
@endif
