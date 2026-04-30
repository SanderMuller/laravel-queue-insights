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
            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
        </svg>
        <span>Back to <span class="font-mono">{{ $shortLabel }}</span></span>
    </button>
@endif
