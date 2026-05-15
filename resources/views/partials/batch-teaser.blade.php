{{-- Batch membership teaser. Sits in the modal left rail alongside the
    parent-lineage and chain teasers so operators can see the three pieces
    of job lineage — came from / batched with / runs next — at a glance,
    and one click jumps to the full batch view.

    Expects $batchId in scope. Uses the same indigo brand colour as the
    inline `batch-chip` partial so the visual link between the row chip and
    the modal teaser is obvious. --}}
@php
    /** @var string $batchId */
@endphp
<button type="button"
        data-section="batch"
        x-on:click.stop="$wire.openBatch(@js($batchId))"
        x-on:keydown.enter.stop x-on:keydown.space.stop
        aria-label="Open batch {{ $batchId }}"
        class="mt-3 flex w-full items-center justify-between gap-3 rounded-lg bg-indigo-50/60 p-3 text-left ring-1 ring-indigo-600/15 transition hover:bg-indigo-50 hover:ring-indigo-600/30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:bg-indigo-900/20 dark:ring-indigo-400/25 dark:hover:bg-indigo-900/30">
    <span class="flex min-w-0 items-center gap-2.5">
        <span aria-hidden="true"
              class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-indigo-100 text-indigo-700 ring-1 ring-inset ring-indigo-600/20 dark:bg-indigo-900/50 dark:text-indigo-300 dark:ring-indigo-400/30">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
            </svg>
        </span>
        <span class="min-w-0">
            <span class="block text-[10px] font-medium uppercase tracking-wider text-indigo-700 dark:text-indigo-300">Batch</span>
            <span class="mt-0.5 block truncate font-mono text-xs text-gray-900 dark:text-gray-100">{{ $batchId }}</span>
        </span>
    </span>
    <span class="shrink-0 text-[10px] font-medium text-indigo-700 dark:text-indigo-300">Open →</span>
</button>
