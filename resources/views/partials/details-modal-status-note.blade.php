{{--
    Status banner — capture mode said "don't persist this payload" (closure / sleep / etc).
    Visually identical across all /ui variants of the right column; extracted so the picker
    options don't duplicate the same amber alert markup.

    Props:
      - $reason: ?string — raw reason slug from `payload_reason`, optional.
--}}
<div class="flex gap-3 rounded-lg bg-amber-50 dark:bg-amber-900/40 p-3 text-sm text-amber-900 dark:text-amber-200 ring-1 ring-inset ring-amber-600/20 dark:ring-amber-400/30">
    <x-queue-insights::icon-warning-triangle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"/>
    <div class="min-w-0">
        <p class="font-medium">Payload not persisted</p>
        @if($reason ?? null)
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">Reason: {{ str_replace('_', ' ', $reason) }}</p>
        @endif
    </div>
</div>
