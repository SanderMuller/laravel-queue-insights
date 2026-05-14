@php
    /**
     * Silenced pane — pre-filtered listing of failed + completed jobs for
     * classes in `queue-insights.silenced`. The Failed tab's "Show silenced"
     * checkbox + URL `?fs=1` reveals the same rows inline; this pane is the
     * one-click landing for "what's currently muted".
     *
     * Paginated per axis — silenced classes are typically the spammiest
     * traffic so a one-page-per-axis cap underrepresented activity. Default
     * 10 per page, URL-bound via `sfp` / `scp` (page) and `sfpp` / `scpp`
     * (per page). Operators who need deep history can also toggle the
     * per-pane "Show silenced" checkbox on the main Failed/Completed panes.
     *
     * Required scope vars:
     *   $silencedClasses             — list<string> FQCNs in `queue-insights.silenced`
     *   $silencedPatterns            — list<string> globs in `queue-insights.silenced_patterns`
     *   $silencedFailedRows          — RowEnricher::failed() output, current page
     *   $silencedCompletedRows       — RowEnricher::completed() output, current page
     *   $silencedFailedPaginator     — \Illuminate\Pagination\LengthAwarePaginator
     *   $silencedCompletedPaginator  — \Illuminate\Pagination\LengthAwarePaginator
     *   $perPageOptions              — list<int> shared with the main Failed/Completed panes
     *   $scopeConnection        — ?string scope (drives the empty-state message
     *                             so an operator on /queue-insights/{conn} sees
     *                             "No silenced-class activity on the {conn} connection"
     *                             instead of the un-scoped "No silenced-class…
     *                             recorded" framing).
     */
    $emptyFailedMessage = $scopeConnection !== null
        ? "No silenced-class failures on the {$scopeConnection} connection."
        : 'No silenced-class failures recorded.';
    $emptyCompletedMessage = $scopeConnection !== null
        ? "No silenced-class completed jobs on the {$scopeConnection} connection."
        : 'No silenced-class completed jobs recorded.';
@endphp

<div class="flex flex-col gap-6">
    {{-- Header — silenced class roster + reminder of the contract. --}}
    <section>
        <h2 class="text-sm font-semibold tracking-tight text-gray-700 dark:text-gray-300">
            Silenced classes <span class="font-normal text-gray-500 dark:text-gray-400">({{ count($silencedClasses) }})</span>
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
            Failures + completed runs for these classes are hidden from the default Failed and Completed lists. Counter writes are preserved — removing a class from <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[11px] dark:bg-gray-800">queue-insights.silenced</code> immediately re-surfaces its history.
        </p>
        @if(count($silencedClasses) > 0)
            <ul role="list" class="mt-3 flex flex-wrap gap-1.5">
                @foreach($silencedClasses as $silencedClass)
                    <li>
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 font-mono text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">
                            {{ $silencedClass }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if(count($silencedPatterns ?? []) > 0)
            <h3 class="mt-4 text-sm font-semibold tracking-tight text-gray-700 dark:text-gray-300">
                Silenced patterns <span class="font-normal text-gray-500 dark:text-gray-400">({{ count($silencedPatterns) }})</span>
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[11px] dark:bg-gray-800">Str::is</code> globs from <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[11px] dark:bg-gray-800">queue-insights.silenced_patterns</code>. Any class whose FQCN matches is silenced on the same surfaces as the exact list.
            </p>
            <ul role="list" class="mt-3 flex flex-wrap gap-1.5">
                @foreach($silencedPatterns as $pattern)
                    <li>
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 font-mono text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">
                            {{ $pattern }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Failed (silenced) — uses the same row partial as the main Failed pane
        so retry button / chain chip / batch chip behaviour stays in lockstep. --}}
    <section>
        <h3 class="mb-2 text-sm font-semibold tracking-wide text-red-700 dark:text-red-300">Failed <span class="font-normal text-red-500 dark:text-red-400 tabular-nums">({{ number_format($silencedFailedPaginator->total()) }})</span></h3>
        @if($silencedFailedPaginator->total() === 0)
            <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
                {{ $emptyFailedMessage }}
            </div>
        @else
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-sm font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
                    <div class="col-span-5">Job</div>
                    <div class="col-span-2">Queue</div>
                    <div class="col-span-2 text-right">Runtime</div>
                    <div class="col-span-2 text-right">Failed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach($silencedFailedRows as $f)
                        @include('queue-insights::partials.failed-list-row', ['f' => $f])
                    @endforeach
                </ul>
                @include('queue-insights::partials.pagination-controls', [
                    'paginator' => $silencedFailedPaginator,
                    'gotoMethod' => 'gotoSilencedFailedPage',
                    'perPageModel' => 'silencedFailedPerPage',
                    'perPageOptions' => $perPageOptions,
                ])
            </div>
        @endif
    </section>

    {{-- Completed (silenced) — same shape, same row partial. --}}
    <section>
        <h3 class="mb-2 text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-300">Completed <span class="font-normal text-gray-400 dark:text-gray-400 tabular-nums">({{ number_format($silencedCompletedPaginator->total()) }})</span></h3>
        @if($silencedCompletedPaginator->total() === 0)
            <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-300">
                {{ $emptyCompletedMessage }}
            </div>
        @else
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-sm font-medium text-gray-500 dark:border-white/10 dark:text-gray-300">
                    <div class="col-span-5">Job</div>
                    <div class="col-span-2">Queue</div>
                    <div class="col-span-2 text-right">Runtime</div>
                    <div class="col-span-2 text-right">Completed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach($silencedCompletedRows as $row)
                        @include('queue-insights::partials.completed-row', ['row' => $row])
                    @endforeach
                </ul>
                @include('queue-insights::partials.pagination-controls', [
                    'paginator' => $silencedCompletedPaginator,
                    'gotoMethod' => 'gotoSilencedCompletedPage',
                    'perPageModel' => 'silencedCompletedPerPage',
                    'perPageOptions' => $perPageOptions,
                ])
            </div>
        @endif
    </section>
</div>
