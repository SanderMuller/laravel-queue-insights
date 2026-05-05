@php
    /**
     * Silenced pane — pre-filtered listing of failed + completed jobs for
     * classes in `queue-insights.silenced`. The Failed tab's "Show silenced"
     * checkbox + URL `?fs=1` reveals the same rows inline; this pane is the
     * one-click landing for "what's currently muted".
     *
     * Mirrors Horizon's "Silenced jobs" tab in spirit: a roster, not a
     * paginated archive — capped at one page per axis. Operators who need
     * deep history toggle the per-pane "Show silenced" checkbox on the
     * main Failed/Completed panes.
     *
     * Required scope vars:
     *   $silencedClasses        — list<string> FQCNs in `queue-insights.silenced`
     *   $silencedFailedRows     — RowEnricher::failed() output, capped at PER_PAGE
     *   $silencedCompletedRows  — RowEnricher::completed() output, capped at PER_PAGE
     */
@endphp

<div class="flex flex-col gap-6">
    {{-- Header — silenced class roster + reminder of the contract. --}}
    <section>
        <h2 class="text-sm font-semibold tracking-tight text-gray-700">
            Silenced classes <span class="font-normal text-gray-500">({{ count($silencedClasses) }})</span>
        </h2>
        <p class="mt-1 text-xs text-gray-500">
            Failures + completed runs for these classes are hidden from the default Failed and Completed lists. Counter writes are preserved — removing a class from <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[11px]">queue-insights.silenced</code> immediately re-surfaces its history.
        </p>
        <ul role="list" class="mt-3 flex flex-wrap gap-1.5">
            @foreach($silencedClasses as $silencedClass)
                <li>
                    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 font-mono text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10">
                        {{ $silencedClass }}
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Failed (silenced) — uses the same row partial as the main Failed pane
        so retry button / chain chip / batch chip behaviour stays in lockstep. --}}
    <section>
        <h3 class="text-sm font-semibold tracking-tight text-gray-700">
            Failed <span class="font-normal text-gray-500">({{ count($silencedFailedRows) }})</span>
        </h3>
        @if(count($silencedFailedRows) === 0)
            <div class="mt-3 rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                No silenced-class failures recorded.
            </div>
        @else
            <div class="mt-3 rounded-lg bg-white ring-1 ring-gray-950/5">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                    <div class="col-span-4">Job</div>
                    <div class="col-span-3">Queue</div>
                    <div class="col-span-2 text-right">Attempts</div>
                    <div class="col-span-2 text-right">Failed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5">
                    @foreach($silencedFailedRows as $f)
                        @include('queue-insights::partials.failed-list-row', ['f' => $f])
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- Completed (silenced) — same shape, same row partial. --}}
    <section>
        <h3 class="text-sm font-semibold tracking-tight text-gray-700">
            Completed <span class="font-normal text-gray-500">({{ count($silencedCompletedRows) }})</span>
        </h3>
        @if(count($silencedCompletedRows) === 0)
            <div class="mt-3 rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                No silenced-class completed jobs recorded.
            </div>
        @else
            <div class="mt-3 rounded-lg bg-white ring-1 ring-gray-950/5">
                <div class="grid grid-cols-12 items-center gap-4 border-b border-gray-950/5 px-4 py-2 text-xs font-medium text-gray-500">
                    <div class="col-span-4">Job</div>
                    <div class="col-span-3">Queue</div>
                    <div class="col-span-2 text-right">Runtime</div>
                    <div class="col-span-2 text-right">Completed</div>
                    <div class="col-span-1"></div>
                </div>
                <ul role="list" class="divide-y divide-gray-950/5">
                    @foreach($silencedCompletedRows as $row)
                        @include('queue-insights::partials.completed-row', ['row' => $row])
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
