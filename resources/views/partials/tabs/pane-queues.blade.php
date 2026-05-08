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
        <h3 class="mb-2 text-xs font-semibold tracking-wide text-red-700 dark:text-red-300">Needs attention <span class="font-normal text-red-500 dark:text-red-400 tabular-nums">({{ count($atRisk) }})</span></h3>
        <div class="mb-5 rounded-lg bg-white ring-1 ring-red-600/20 dark:bg-gray-900 dark:ring-red-400/30">
            <div class="grid grid-cols-12 items-center gap-4 border-b border-red-200/60 px-4 py-2 text-xs font-medium text-red-700/80 dark:border-red-400/20 dark:text-red-300">
                <div class="col-span-4">Queue</div>
                <div class="col-span-4 grid grid-cols-3 text-center">
                    <div>Depth</div><div>In-flight</div><div>Delayed</div>
                </div>
                <div class="col-span-2 text-right">Wait</div>
                <div class="col-span-2 text-right">Status</div>
            </div>
            <ul role="list" class="divide-y divide-red-200/60 dark:divide-red-400/20">
                @foreach($atRisk as $q)
                    @include('queue-insights::partials.queue-row', ['q' => $q])
                @endforeach
            </ul>
        </div>
    @endif
    @if(count($healthy) > 0)
        @if(count($atRisk) > 0)
            <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-300">Healthy <span class="font-normal text-gray-400 dark:text-gray-400 tabular-nums">({{ count($healthy) }})</span></h3>
        @endif
        <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
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
