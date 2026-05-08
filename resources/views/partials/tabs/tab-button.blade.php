@php
    /**
     * Tab strip button — toggles the surrounding `x-data="{ tab }"` state and
     * mirrors the active tab into `window.location.hash`. Required scope vars:
     *
     *   string      $name    tab key, e.g. 'overview', 'queues'
     *   string      $label
     *   int|null    $badge   number rendered after the label, null = no badge
     */
@endphp
<button type="button"
        x-on:click="tab='{{ $name }}'; history.replaceState(null,'','#qi-{{ $name }}')"
        x-bind:class="tab==='{{ $name }}' ? 'border-emerald-500 text-emerald-700 dark:text-emerald-300' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-700 dark:hover:text-gray-100'"
        class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium tabular-nums">
    {{ $label }}
    @if($badge !== null)
        <span class="text-xs font-normal text-gray-400 dark:text-gray-400">{{ $badge }}</span>
    @endif
</button>
