@php
    /**
     * Jobs dispatched during a single scheduled run. Click-through to the
     * existing queue-side modals — silenced filter is NOT honoured here:
     * once an operator has the uuid in hand, the modal must always open
     * (CLAUDE.md silenced-jobs rule).
     *
     * Required scope:
     *   list<string>  $uuids  Job uuids dispatched during the run, in
     *                         queued-at order (oldest first).
     */
    $uuids ??= [];
@endphp

<section data-section="schedule-correlated-jobs" class="mt-6">
    <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
        Jobs dispatched during this run
    </p>
    @if($uuids === [])
        <p class="mt-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-2 text-[11px] text-gray-500 dark:text-gray-300 ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
            No jobs were dispatched during this run.
        </p>
    @else
        <ol role="list" class="mt-2 overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach($uuids as $uuid)
                <li class="bg-white dark:bg-gray-900">
                    <button type="button"
                            wire:click="openJobByUuid('{{ $uuid }}')"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs transition hover:bg-gray-50 dark:hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <code class="truncate font-mono text-[11px] text-gray-700 dark:text-gray-300">{{ $uuid }}</code>
                        <span class="shrink-0 text-[10px] font-medium text-emerald-700 dark:text-emerald-300">Open →</span>
                    </button>
                </li>
            @endforeach
        </ol>
        <p class="mt-2 text-[11px] text-gray-400 dark:text-gray-400">
            {{ count($uuids) }} {{ count($uuids) === 1 ? 'job' : 'jobs' }} attributed to this scheduled run via the in-flight schedule context.
        </p>
    @endif
</section>
