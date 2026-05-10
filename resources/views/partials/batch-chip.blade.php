@php
    /** @var string $batchId */
@endphp
<span class="relative inline-block" x-data="{ open: false }"
      x-on:mouseenter="open = true" x-on:mouseleave="open = false"
      x-on:focusin="open = true" x-on:focusout="open = false">
    <button type="button"
            x-on:click.stop="$wire.openBatch(@js($batchId))"
            x-on:keydown.enter.stop x-on:keydown.space.stop
            class="inline-flex max-w-[18ch] items-center gap-1 rounded bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 font-mono text-[10px] font-medium text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-600/20 dark:ring-indigo-400/30 hover:bg-indigo-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-indigo-500"
            aria-label="Open batch {{ $batchId }}">
        <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M3.5 3.75A1.75 1.75 0 0 1 5.25 2h9.5A1.75 1.75 0 0 1 16.5 3.75v3.5A1.75 1.75 0 0 1 14.75 9h-9.5A1.75 1.75 0 0 1 3.5 7.25v-3.5ZM3.5 12.75A1.75 1.75 0 0 1 5.25 11h9.5a1.75 1.75 0 0 1 1.75 1.75v3.5A1.75 1.75 0 0 1 14.75 18h-9.5A1.75 1.75 0 0 1 3.5 16.25v-3.5Z"/>
        </svg>
        <span class="truncate">{{ $batchId }}</span>
    </button>
    <span role="tooltip" x-show="open" x-cloak
          class="pointer-events-none absolute -top-2 left-1/2 z-20 w-max max-w-xs -translate-x-1/2 -translate-y-full whitespace-normal rounded-md bg-gray-900 px-2.5 py-1.5 text-left text-[11px] font-normal leading-snug text-white shadow-lg ring-1 ring-white/10">
        <span class="block text-gray-300">Batch</span>
        <span class="block font-mono break-all text-white">{{ $batchId }}</span>
        <span class="mt-1 block text-gray-400">Click to open</span>
        <span class="absolute left-1/2 -bottom-1 size-2 -translate-x-1/2 rotate-45 bg-gray-900" aria-hidden="true"></span>
    </span>
</span>
