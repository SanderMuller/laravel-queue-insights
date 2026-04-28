@php
    /**
     * Pending pane — in-flight + pending-now + delayed sub-tables.
     * Each sub-section renders only when its row set is non-empty.
     *
     * Required scope vars:
     *   $hasPendingAny, $inFlightRows, $pendingRows, $delayedRows
     */
@endphp
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
