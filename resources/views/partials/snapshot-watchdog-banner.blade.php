@php
    /**
     * Top-level red banner shown when the snapshot command appears dead —
     * no `live:depth:{c}:{q}` keys are present for any configured queue
     * (90s TTL elapsed). The dashboard is otherwise frozen, so the banner
     * is the only signal an operator gets without an external watchdog.
     *
     * Required scope vars:
     *   $snapshotCommandDead  bool
     */
@endphp
@if($snapshotCommandDead)
    <div role="alert" class="flex items-start gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-inset ring-red-600/20 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-400/30">
        <svg class="mt-0.5 size-5 shrink-0 text-red-700 dark:text-red-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.169 2.625-1.515 2.625H3.72c-1.346 0-2.188-1.458-1.515-2.625l6.28-10.875ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
        </svg>
        <div class="min-w-0 flex-1">
            <p class="font-semibold">Snapshot command appears dead.</p>
            <p class="mt-0.5 text-xs opacity-90">
                No <code class="rounded bg-red-200/60 px-1 py-0.5 font-mono text-[11px] dark:bg-red-400/20">live:depth</code> keys are present for any configured queue —
                the <code class="rounded bg-red-200/60 px-1 py-0.5 font-mono text-[11px] dark:bg-red-400/20">queue-insights:snapshot</code> command has been silent for at
                least 90 seconds. Live counts and alert evaluations are stale until the scheduler / supervisor restores it.
            </p>
        </div>
    </div>
@endif
