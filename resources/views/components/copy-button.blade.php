@props([
    /** Element id whose textContent the JS handler reads. */
    'target' => '',
    /** Accessible label (sr-only when `text` is empty). */
    'label' => 'Copy',
    /** Optional inline button text — when null/empty the button is icon-only. */
    'text' => null,
    /** Visual variant: `pill` (bordered chip) or `icon` (square icon-only). */
    'variant' => 'pill',
])

@php
    $isIconOnly = $text === null || $text === '';
    // Note: hover/focus styles via Tailwind. Pressed state (`data-qi-copied`)
    // styled via inline CSS in layouts/app.blade.php — Tailwind CDN's JIT misses
    // arbitrary `data-[]:` variants in anonymous components, so the bg/icon swap
    // can't depend on Tailwind classes.
    $variantClasses = match ($variant) {
        'icon' => 'inline-flex size-6 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-950/5 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500',
        default => 'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500',
    };
    $iconSize = 'size-3.5';
@endphp

<button type="button"
        data-qi-copy
        data-qi-copy-target="{{ $target }}"
        aria-label="{{ $label }}"
        {{ $attributes->class([$variantClasses, 'transition-colors']) }}>
    {{-- Default copy icon — hidden via .qi-copy-icon { display: none } when copied. --}}
    <svg class="qi-copy-icon {{ $iconSize }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M13.06 4.566a5.5 5.5 0 0 0-1.5-1.5A2.5 2.5 0 0 0 9 1.5H7A2.5 2.5 0 0 0 4.5 4H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-.5A2.5 2.5 0 0 0 7 1.5H7Zm-2.56.434a1 1 0 1 1 2 0v.5a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5V5a1 1 0 1 1 2 0v.5h0Z" fill-rule="evenodd" clip-rule="evenodd"/>
        <path d="M14 7h1a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V7Z"/>
    </svg>
    {{-- Check icon (copied state) — display flipped by inline CSS keyed off data-qi-copied. --}}
    <svg class="qi-copy-check {{ $iconSize }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/>
    </svg>

    @unless($isIconOnly)
        <span class="qi-copy-text">{{ $text }}</span>
        <span class="qi-copy-text-copied">Copied</span>
    @endunless
</button>
