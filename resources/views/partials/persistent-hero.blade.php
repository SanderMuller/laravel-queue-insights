@php
    /**
     * Persistent hero — sparkline (full card) alongside a 6-KPI panel.
     * Always visible across tabs so the throughput trend is the last thing
     * to fall off-screen. The sparkline component renders its own card
     * chrome (ring + padding + axis labels), so we let it size itself
     * rather than clamping its height.
     *
     * Required scope vars:
     *   $throughput, $stats, $totalDepth, $totalInFlight, $fmtMs
     */
@endphp
<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-queue-insights::throughput-sparkline :throughput="$throughput"/>
    </div>
    {{-- Aurora accent — emerald glow + diagonal shimmer behind the headline
         stats panel, plus a top hairline + `live · last 60s` caption above
         "Jobs / min". Mirrors the Aurora header chrome at a quieter
         intensity (opacity-15 vs opacity-30) so the panel still reads as
         secondary to the sparkline beside it. --}}
    <dl aria-label="Headline stats" class="relative isolate overflow-hidden grid grid-cols-3 gap-x-4 gap-y-3 rounded-xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @include('queue-insights::partials.aurora-bg')
        <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-400/40 to-transparent" aria-hidden="true"></div>
        <div class="col-span-3 -mb-1 flex items-center gap-2">
            <span class="relative flex size-1.5">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex size-1.5 rounded-full bg-emerald-400"></span>
            </span>
            <span class="text-[10px] font-medium uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300/80">live · last 60s</span>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">Jobs / min</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($stats['jobs_per_minute']) }}</dd>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">Past hour</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($stats['jobs_past_hour']) }}</dd>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">Failed / hr</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $stats['failed_past_hour'] > 0 ? 'text-red-700 dark:text-red-300' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($stats['failed_past_hour']) }}</dd>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">Backlog</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $totalDepth > 1000 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($totalDepth) }}</dd>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">In-flight</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($totalInFlight) }}</dd>
        </div>
        <div>
            <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">p95 wait</dt>
            <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $stats['max_wait_ms'] !== null && $stats['max_wait_ms'] > 5_000 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $fmtMs($stats['max_wait_ms']) }}</dd>
        </div>
    </dl>
</div>
