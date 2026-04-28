@props([
    'label',
    'value',
    'size' => 'md',
])

@php
    $padding = $size === 'sm' ? 'px-1.5' : 'px-2';
    $valueColor = $size === 'sm' ? 'text-gray-700' : 'text-gray-800';
@endphp

<dl class="inline-flex items-center divide-x divide-gray-950/10 overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
    <dt class="bg-gray-50 {{ $padding }} py-0.5 font-medium text-gray-500">{{ $label }}</dt>
    <dd class="bg-gray-50 {{ $padding }} py-0.5 font-mono {{ $valueColor }}">{{ $value ?? '—' }}</dd>
</dl>
