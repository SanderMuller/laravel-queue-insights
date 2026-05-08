@php
    /** @var string $batchId */
    $shortId = strlen($batchId) > 8 ? substr($batchId, 0, 8) : $batchId;
@endphp
<button type="button"
        x-on:click.stop="$wire.openBatch(@js($batchId))"
        x-on:keydown.enter.stop x-on:keydown.space.stop
        class="inline-flex items-center gap-1 rounded bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 font-mono text-[10px] font-medium text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-600/20 dark:ring-indigo-400/30 hover:bg-indigo-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-indigo-500"
        title="Open batch {{ $batchId }}"
        aria-label="Open batch {{ $batchId }}">
    <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
    </svg>
    <span>{{ $shortId }}</span>
</button>
