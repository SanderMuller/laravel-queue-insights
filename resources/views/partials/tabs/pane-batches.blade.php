@php
    /**
     * Batches pane — single list of tracked batches in
     * BatchReader::recentBatches() order (newest index score first).
     *
     * Required scope vars:
     *   $batches
     */
@endphp
@if(count($batches) === 0)
    <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">No active batches.</div>
@else
    <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-sm font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
            <div class="col-span-5">Batch</div>
            <div class="col-span-3">Progress</div>
            <div class="col-span-2 text-center">Counts</div>
            <div class="col-span-2 text-right">Status</div>
        </div>
        <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach($batches as $batch)
                @include('queue-insights::partials.batch-row', ['batch' => $batch])
            @endforeach
        </ul>
    </div>
@endif
