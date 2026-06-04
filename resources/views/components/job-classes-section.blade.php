@props([
    /** @var list<array<string, mixed>> Per-class 24h aggregates from buildClassRows. */
    'classes' => [],
    /** @var ?string Currently filtered class FQCN (drives the open-by-default + chip). */
    'selectedClass' => null,
])

{{-- Job classes — per-class 24h volume / runtime / p95 / max breakdown. Mounted
    inside the Classes tab (`pane-classes`); rows are clickable to filter the
    Completed list. Card-shape mirrors alert-rules in-alarm + schedule recent
    runs so all table-in-card surfaces share one chrome. --}}
<section class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-950/5 px-5 py-3 dark:border-white/10">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 4.25A2.25 2.25 0 0 1 4.25 2h2.5A2.25 2.25 0 0 1 9 4.25v2.5A2.25 2.25 0 0 1 6.75 9h-2.5A2.25 2.25 0 0 1 2 6.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 2h2.5A2.25 2.25 0 0 1 18 4.25v2.5A2.25 2.25 0 0 1 15.75 9h-2.5A2.25 2.25 0 0 1 11 6.75v-2.5Zm-9 9A2.25 2.25 0 0 1 4.25 11h2.5A2.25 2.25 0 0 1 9 13.25v2.5A2.25 2.25 0 0 1 6.75 18h-2.5A2.25 2.25 0 0 1 2 15.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 11h2.5A2.25 2.25 0 0 1 18 13.25v2.5A2.25 2.25 0 0 1 15.75 18h-2.5A2.25 2.25 0 0 1 11 15.75v-2.5Z"/></svg>
            </span>
            <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">
                Job classes <span class="font-normal text-gray-500 dark:text-gray-400 tabular-nums">(24h · {{ count($classes) }})</span>
            </h3>
            @if($selectedClass)
                <span class="rounded bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-400/30">{{ $selectedClass }}</span>
            @endif
        </div>
        @if($selectedClass)
            <button type="button" wire:click="clearSelectedClass"
                    class="h-8 rounded-md bg-white px-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-950/[0.03] hover:text-gray-900 focus:ring-2 focus:ring-inset focus:ring-emerald-500 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-gray-100">
                Clear filter
            </button>
        @endif
    </div>

    @if(count($classes) === 0)
        <div class="m-5 rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
            No processed jobs in the window.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-300">
                    <th class="whitespace-nowrap py-2 pl-5 pr-3 font-medium">Job</th>
                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Volume</th>
                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Runtime</th>
                    <th class="whitespace-nowrap py-2 pl-3 pr-5 font-medium">Last run</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach($classes as $c)
                    @php
                        $processed = is_numeric($c['processed_24h'] ?? null) ? (int) $c['processed_24h'] : 0;
                        $failed = is_numeric($c['failed_24h'] ?? null) ? (int) $c['failed_24h'] : 0;
                        // Denominator is total runs, not just successes — a class with 0 processed
                        // and N failed should read 100% fail rate, not 0% (codex review).
                        $failRate = ($processed + $failed) > 0 ? ($failed / ($processed + $failed)) * 100 : 0;
                        $avgMs = $c['avg_ms'] ?? null;
                        $p95Ms = $c['p95_ms'] ?? null;
                        $maxMs = $c['max_ms'] ?? null;
                        $lastRunAt = $c['last_run_at'] ?? null;
                    @endphp
                    {{-- @js() safely encodes FQCN for JS string literals — single-quoted
                        `'{{ ... }}'` strips `\J`, `\V` etc as unknown escapes. --}}
                    <tr class="cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500 dark:hover:bg-white/5 dark:focus-visible:bg-emerald-900/30 {{ $selectedClass === $c['class'] ? 'bg-emerald-50/30 dark:bg-emerald-900/20' : '' }}"
                        role="button"
                        tabindex="0"
                        aria-label="Filter by {{ $c['class'] }}"
                        wire:click="selectClass(@js($c['class']))"
                        x-on:keydown.enter.prevent="$wire.selectClass(@js($c['class']))"
                        x-on:keydown.space.prevent="$wire.selectClass(@js($c['class']))">
                        {{-- Job: class FQCN + (optional) fail-rate subtitle --}}
                        <td class="max-w-md py-3 pl-5 pr-3 align-top">
                            <div class="flex items-center gap-1.5">
                                <p class="truncate font-mono text-xs font-medium text-gray-900 dark:text-gray-100">{{ $c['class'] }}</p>
                                @if(($c['silenced'] ?? false) === true)
                                    {{-- Muted badge — class is in `queue-insights.silenced`. Failures
                                         hidden from the Failed list + alert pipeline; throughput / p95
                                         stats stay visible so the operator can still triage. --}}
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10" title="Failures silenced via queue-insights.silenced">silenced</span>
                                @endif
                            </div>
                            @if($failed > 0)
                                <p class="mt-0.5 text-[10px] font-medium tabular-nums text-red-600 dark:text-red-400">{{ number_format($failRate, 1) }}% fail rate</p>
                            @endif
                        </td>
                        {{-- Volume: processed · failed stacked --}}
                        <td class="px-3 py-3 text-right align-top">
                            <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($processed) }}</p>
                            <p class="mt-0.5 text-[10px] font-medium tabular-nums {{ $failed > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-400' }}">{{ $failed > 0 ? number_format($failed) . ' failed' : 'no failures' }}</p>
                        </td>
                        {{-- Runtime: avg headline + p95/max micro-stats underneath --}}
                        <td class="px-3 py-3 text-right align-top">
                            <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $avgMs !== null ? number_format((float) $avgMs, 0) . ' ms' : '—' }}
                                <span class="ml-1 text-[10px] font-normal text-gray-400 dark:text-gray-400">avg</span>
                            </p>
                            @if($p95Ms !== null || $maxMs !== null)
                                <p class="mt-0.5 text-[10px] tabular-nums text-gray-500 dark:text-gray-300">
                                    @if($p95Ms !== null)
                                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ number_format((int) $p95Ms) }}</span><span class="text-gray-400 dark:text-gray-400"> p95</span>
                                    @endif
                                    @if($p95Ms !== null && $maxMs !== null)
                                        <span class="mx-0.5 text-gray-300 dark:text-gray-500">·</span>
                                    @endif
                                    @if($maxMs !== null)
                                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ number_format((int) $maxMs) }}</span><span class="text-gray-400 dark:text-gray-400"> max</span>
                                    @endif
                                </p>
                            @endif
                        </td>
                        {{-- Last run: humanized + absolute subtitle --}}
                        <td class="py-3 pl-3 pr-5 align-top">
                            <x-queue-insights::qi-time :at="$lastRunAt" class="block whitespace-nowrap text-xs text-gray-700 dark:text-gray-300"/>
                            @if($lastRunAt instanceof \Carbon\CarbonInterface)
                                <x-queue-insights::qi-time :at="$lastRunAt" format="absolute-mono" class="mt-0.5 block text-[10px] text-gray-400 dark:text-gray-400"/>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
