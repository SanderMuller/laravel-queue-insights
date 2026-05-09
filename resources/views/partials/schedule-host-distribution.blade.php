@php
    /**
     * Host-id distribution bar chart for one scheduled task.
     *
     * Required scope:
     *   array<string, int>  $distribution  host_id → run count, sorted desc
     *
     * Suppressed entirely when one host or fewer — a single full bar is
     * visual noise. Caller decides whether to render the section at all.
     */
    $distribution ??= [];
    $totalRuns = (int) array_sum($distribution);
@endphp

@if(count($distribution) >= 2 && $totalRuns > 0)
    <section data-section="schedule-host-distribution" class="mt-4">
        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Host distribution</p>
        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-400">
            Across the last {{ number_format($totalRuns) }} runs — answers "is <code class="rounded bg-gray-950/5 dark:bg-white/10 px-1 font-mono text-[10px]">onOneServer</code> distributing fairly?"
        </p>
        <ul role="list" class="mt-3 flex flex-col gap-2">
            @foreach($distribution as $host => $count)
                @php
                    $pct = (int) round(($count / $totalRuns) * 100);
                @endphp
                <li class="grid grid-cols-12 items-center gap-2 text-xs">
                    <span class="col-span-3 truncate font-mono text-gray-700 dark:text-gray-300" title="{{ $host }}">{{ $host }}</span>
                    <div class="col-span-7 h-2 overflow-hidden rounded-sm bg-gray-100 dark:bg-gray-800">
                        <div class="h-full bg-emerald-300 dark:bg-emerald-400/60"
                             style="width: {{ max(2, $pct) }}%"></div>
                    </div>
                    <span class="col-span-2 text-right tabular-nums text-gray-500 dark:text-gray-300">
                        {{ number_format($count) }}<span class="text-gray-400 dark:text-gray-400"> · {{ $pct }}%</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
