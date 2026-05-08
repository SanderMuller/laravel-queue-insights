@props([
    'label',
    'value',
    'size' => 'md',
])

@php
    $padding = $size === 'sm' ? 'px-1.5' : 'px-2';
    $valueColor = $size === 'sm' ? 'text-gray-700 dark:text-gray-300' : 'text-gray-800 dark:text-gray-200';
@endphp

<dl class="inline-flex items-center divide-x divide-gray-950/10 overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
    <dt class="bg-gray-50 dark:bg-gray-800 {{ $padding }} py-0.5 font-medium text-gray-500 dark:text-gray-300">{{ $label }}</dt>
    <dd class="bg-gray-50 dark:bg-gray-800 {{ $padding }} py-0.5 font-mono {{ $valueColor }}">{{ $value ?? '—' }}</dd>
</dl>