@props([
    /** Tooltip placement relative to the trigger. */
    'placement' => 'top',
    /** Optional inline classes for the trigger wrapper. */
    'triggerClass' => 'inline-flex items-center gap-1',
])

@php
    $isBottom = $placement === 'bottom';
    $tipPosition = $isBottom
        ? 'top-full left-1/2 mt-2 -translate-x-1/2'
        : '-top-2 left-1/2 -translate-x-1/2 -translate-y-full';
    $arrowPosition = $isBottom
        ? 'left-1/2 -top-1 -translate-x-1/2'
        : 'left-1/2 -bottom-1 -translate-x-1/2';
    // Per-render id wires the trigger's aria-describedby to the popup's
    // role="tooltip" so screen readers announce the explanation when the
    // trigger gains focus. Different per render, which is fine — the
    // relationship only needs to hold for one render cycle.
    $tipId = 'qi-hint-' . bin2hex(random_bytes(4));
@endphp

<span class="relative inline-block" x-data="{ open: false }"
      x-on:mouseenter="open = true" x-on:mouseleave="open = false"
      x-on:focusin="open = true" x-on:focusout="open = false">
    <span class="{{ $triggerClass }}" tabindex="0" aria-describedby="{{ $tipId }}">
        {{ $slot }}
    </span>
    <span id="{{ $tipId }}" role="tooltip" x-show="open" x-cloak
          class="pointer-events-none absolute {{ $tipPosition }} z-20 w-max max-w-xs whitespace-normal rounded-md bg-gray-900 px-2.5 py-1.5 text-left text-[11px] font-normal leading-snug text-white shadow-lg ring-1 ring-white/10">
        {{ $tip }}
        <span class="absolute {{ $arrowPosition }} size-2 rotate-45 bg-gray-900" aria-hidden="true"></span>
    </span>
</span>
