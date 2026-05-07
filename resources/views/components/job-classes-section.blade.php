@props([
    /** @var list<array<string, mixed>> Per-class 24h aggregates from buildClassRows. */
    'classes' => [],
    /** @var ?string Currently filtered class FQCN (drives the open-by-default + chip). */
    'selectedClass' => null,
])

{{-- Job classes — per-class 24h volume / runtime / p95 / max breakdown. Mounted
    inside the Classes tab (`pane-classes`); rows are clickable to filter the
    Completed list. The `<details>` wrapper is kept (vs a flat <section>) so the
    `summary` block keeps the chevron + clear-filter pattern; always-open here
    because the user already chose to land on this tab. --}}
<section>
    <details class="group" open>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg px-3 py-2.5 -mx-3 hover:bg-gray-950/[0.03] focus-visible:bg-gray-950/[0.03] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
            <div class="flex items-center gap-2.5">
                <span class="h-5 w-1 rounded bg-gray-300 group-open:bg-emerald-500" aria-hidden="true"></span>
                <h2 class="text-sm font-semibold tracking-tight text-gray-700">
                    Job classes <span class="font-normal text-gray-500">(24h · {{ count($classes) }})</span>
                </h2>
                @if($selectedClass)
                    <span class="rounded bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">{{ $selectedClass }}</span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if($selectedClass)
                    {{-- Clear filter sits inside <summary>. `.stop.prevent` is belt-and-suspenders:
                        major browsers special-case interactive descendants and skip the toggle, but
                        spec wording is loose; preventDefault makes the no-toggle behaviour explicit
                        (codex review). --}}
                    <button type="button" wire:click="clearSelectedClass" x-on:click.stop.prevent
                            class="rounded text-xs font-medium text-emerald-700 hover:text-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        Clear filter
                    </button>
                @endif
                <svg class="size-4 text-gray-400 transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                </svg>
            </div>
        </summary>

        <div class="mt-4">
            @if(count($classes) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    No processed jobs in the window.
                </div>
            @else
                <div class="-mx-6 -my-2 overflow-x-auto sm:-mx-8 lg:-mx-10">
                    <div class="inline-block min-w-full px-6 py-2 align-middle sm:px-8 lg:px-10">
                        <table class="w-full text-sm">
                            <thead>
                            <tr class="text-left text-xs font-medium text-gray-500">
                                <th class="whitespace-nowrap py-2 pr-3 font-medium">Job</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Volume</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Runtime</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">Last run</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-950/5">
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
                                <tr class="cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500 {{ $selectedClass === $c['class'] ? 'bg-emerald-50/30' : '' }}"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Filter by {{ $c['class'] }}"
                                    wire:click="selectClass(@js($c['class']))"
                                    x-on:keydown.enter.prevent="$wire.selectClass(@js($c['class']))"
                                    x-on:keydown.space.prevent="$wire.selectClass(@js($c['class']))">
                                    {{-- Job: class FQCN + (optional) fail-rate subtitle --}}
                                    <td class="max-w-md py-3 pr-3 align-top">
                                        <div class="flex items-center gap-1.5">
                                            <p class="truncate font-mono text-xs font-medium text-gray-900">{{ $c['class'] }}</p>
                                            @if(($c['silenced'] ?? false) === true)
                                                {{-- Muted badge — class is in `queue-insights.silenced`. Failures
                                                     hidden from the Failed list + alert pipeline; throughput / p95
                                                     stats stay visible so the operator can still triage. --}}
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10" title="Failures silenced via queue-insights.silenced">silenced</span>
                                            @endif
                                        </div>
                                        @if($failed > 0)
                                            <p class="mt-0.5 text-[10px] font-medium tabular-nums text-red-600">{{ number_format($failRate, 1) }}% fail rate</p>
                                        @endif
                                    </td>
                                    {{-- Volume: processed · failed stacked --}}
                                    <td class="px-3 py-3 text-right align-top">
                                        <p class="text-sm font-medium tabular-nums text-gray-900">{{ number_format($processed) }}</p>
                                        <p class="mt-0.5 text-[10px] font-medium tabular-nums {{ $failed > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $failed > 0 ? number_format($failed) . ' failed' : 'no failures' }}</p>
                                    </td>
                                    {{-- Runtime: avg headline + p95/max micro-stats underneath --}}
                                    <td class="px-3 py-3 text-right align-top">
                                        <p class="text-sm font-medium tabular-nums text-gray-900">
                                            {{ $avgMs !== null ? number_format((float) $avgMs, 0) . ' ms' : '—' }}
                                            <span class="ml-1 text-[10px] font-normal text-gray-400">avg</span>
                                        </p>
                                        @if($p95Ms !== null || $maxMs !== null)
                                            <p class="mt-0.5 text-[10px] tabular-nums text-gray-500">
                                                @if($p95Ms !== null)
                                                    <span class="font-medium text-gray-700">{{ number_format((int) $p95Ms) }}</span><span class="text-gray-400"> p95</span>
                                                @endif
                                                @if($p95Ms !== null && $maxMs !== null)
                                                    <span class="mx-0.5 text-gray-300">·</span>
                                                @endif
                                                @if($maxMs !== null)
                                                    <span class="font-medium text-gray-700">{{ number_format((int) $maxMs) }}</span><span class="text-gray-400"> max</span>
                                                @endif
                                            </p>
                                        @endif
                                    </td>
                                    {{-- Last run: humanized + absolute subtitle --}}
                                    <td class="px-3 py-3 align-top">
                                        <x-queue-insights::qi-time :at="$lastRunAt" class="block whitespace-nowrap text-xs text-gray-700"/>
                                        @if($lastRunAt instanceof \Carbon\CarbonInterface)
                                            <x-queue-insights::qi-time :at="$lastRunAt" format="absolute-mono" class="mt-0.5 block text-[10px] text-gray-400"/>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </details>
</section>
