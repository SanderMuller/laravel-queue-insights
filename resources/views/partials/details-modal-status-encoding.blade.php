{{--
    Status banner — sanitizer threw while JSON-encoding the payload. Visually identical
    across all /ui variants of the right column.
--}}
<div class="flex gap-3 rounded-lg bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-900 dark:text-red-200 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30">
    <x-queue-insights::icon-error-circle class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400"/>
    <div class="min-w-0">
        <p class="font-medium">Payload encoding failed</p>
        <p class="mt-1 text-xs text-red-800 dark:text-red-200">Sanitizer could not JSON-encode the payload for this job.</p>
    </div>
</div>
