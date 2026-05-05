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
    // "Show silenced" toggle (URL `?cs=1`) — independent from the failed-pane
    // toggle so operators can dig into silenced failures without unmuting
    // silenced successes (or vice versa).
    'silenceModel' => 'completedIncludeSilenced',
])

@if($amountCompletedRows === 0)
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
            'total' => $completedTotal ?? $amountCompletedRows,
            'perPage' => $completedPerPage ?? $amountCompletedRows,
            'gotoMethod' => 'gotoCompletedPage',
        ])
    </div>
@endif
