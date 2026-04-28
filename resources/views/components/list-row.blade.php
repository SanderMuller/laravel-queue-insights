@props([
    'clickable' => true,
    'wireAction' => null,
    'wireArg' => null,
    'ariaLabel' => '',
    'srPrefix' => 'Details',
    'srName' => '',
    'density' => 'standard',
])

@php
    $py = $density === 'compact' ? 'py-3' : 'py-2.5';
@endphp

<li @class([
        "grid grid-cols-12 items-center gap-4 px-4 {$py}",
        'cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500' => $clickable,
    ])
    @if ($clickable && $wireAction !== null)
        role="button"
        tabindex="0"
        aria-label="{{ $ariaLabel }}"
        wire:click="{{ $wireAction }}(@js($wireArg))"
        x-on:keydown.enter.prevent="$wire.{{ $wireAction }}(@js($wireArg))"
        x-on:keydown.space.prevent="$wire.{{ $wireAction }}(@js($wireArg))"
    @endif>
    @if ($srName !== '')
        <span class="sr-only">{{ $srPrefix }} — {{ $srName }}</span>
    @endif
    {{ $slot }}
</li>
