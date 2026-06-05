@php
    /**
     * Queues pane — at-risk + healthy tables. At-risk renders first when
     * non-empty so operators land on the failure surface immediately.
     *
     * Required scope vars:
     *   $queues, $atRisk, $healthy
     */
@endphp
@if(count($queues) === 0)
    <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
        No queues configured. Add entries to <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs dark:bg-white/10">config/queue-insights.php</code> under <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs dark:bg-white/10">snapshots</code>.
    </div>
@else
    @if(count($atRisk) > 0)
        <h3 class="mb-2 flex items-center gap-1.5 text-sm font-semibold tracking-tight text-red-700 dark:text-red-300">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
            Needs attention <span class="font-normal text-red-500 dark:text-red-400 tabular-nums">({{ count($atRisk) }})</span>
        </h3>
        <div class="mb-5 rounded-xl bg-white ring-1 ring-red-600/20 dark:bg-gray-900 dark:ring-red-400/30">
            <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-sm font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
                <div class="col-span-4">Queue</div>
                <div class="col-span-4 grid grid-cols-3 text-center">
                    <div>Depth</div><div>In-flight</div><div>Delayed</div>
                </div>
                <div class="col-span-2 text-right">Wait</div>
                <div class="col-span-2 text-right">Status</div>
            </div>
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($atRisk as $q)
                    @include('queue-insights::partials.queue-row', ['q' => $q])
                @endforeach
            </ul>
        </div>
    @endif
    @if(count($healthy) > 0)
        @if(count($atRisk) > 0)
            <h3 class="mb-2 flex items-center gap-1.5 text-sm font-semibold tracking-tight text-gray-500 dark:text-gray-300">
                <svg class="size-4 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/></svg>
                Healthy <span class="font-normal text-gray-400 dark:text-gray-400 tabular-nums">({{ count($healthy) }})</span>
            </h3>
        @endif
        <div class="rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-sm font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
                <div class="col-span-4">Queue</div>
                <div class="col-span-4 grid grid-cols-3 text-center">
                    <div>Depth</div><div>In-flight</div><div>Delayed</div>
                </div>
                <div class="col-span-2 text-right">Wait</div>
                <div class="col-span-2 text-right">Status</div>
            </div>
            <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($healthy as $q)
                    @include('queue-insights::partials.queue-row', ['q' => $q])
                @endforeach
            </ul>
        </div>
    @endif
@endif
