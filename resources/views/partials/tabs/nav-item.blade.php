@php
    /**
     * Sidebar nav item — vertical section switcher button. Toggles the
     * surrounding `x-data="{ tab }"` state and mirrors the active section
     * into `window.location.hash`. Required scope vars:
     *
     *   string      $name   section key, e.g. 'overview', 'pending'
     *   string      $label
     *   int|null    $badge  muted count after the label, null = no count
     *   string|null $icon   heroicon path `d` (single path, evenodd); null = none
     */
    $icon = $icon ?? null;
@endphp
<button type="button"
        x-on:click="setTab('{{ $name }}')"
        x-bind:aria-current="tab === '{{ $name }}' ? 'page' : null"
        x-bind:class="tab === '{{ $name }}'
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-gray-100'"
        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-1.5 text-left text-sm font-medium transition">
    @if($icon !== null)
        <svg class="size-4 shrink-0 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" clip-rule="evenodd" d="{{ $icon }}"/>
        </svg>
    @endif
    <span class="flex-1 truncate">{{ $label }}</span>
    @if($badge !== null)
        <span class="shrink-0 text-xs font-normal tabular-nums text-gray-400 dark:text-gray-400">{{ $badge }}</span>
    @endif
</button>
