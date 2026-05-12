@php
    // Top frame of `QueueInsightsDashboard::$chainBackStack`. Shape:
    // `{type: string, id: int|string, class: ?string}`. Null → partial
    // renders nothing (user landed on this modal directly, no chain
    // step to back to). @props is component-specific and emits residue
    // under the L11.0/L12.0 Blade compilers, so we use plain defaults.
    $frame ??= null;
@endphp
@if(is_array($frame) && isset($frame['type']))
    @php
        $label = is_string($frame['class'] ?? null) && $frame['class'] !== ''
            ? $frame['class']
            : 'previous';
        // Trim FQCN to its leaf for compactness — full class still
        // available in the title attribute for hover.
        $shortLabel = ($p = strrpos($label, '\\')) !== false ? substr($label, $p + 1) : $label;
    @endphp
    <button type="button"
            wire:click="chainBack"
            aria-label="Back to {{ $label }}"
            title="Back to {{ $label }}"
            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
        <x-queue-insights::icon-chevron-left class="size-3.5"/>
        <span>Back to <span class="font-mono">{{ $shortLabel }}</span></span>
    </button>
@endif
