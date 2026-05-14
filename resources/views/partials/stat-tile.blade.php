@php
    /**
     * @var string $label
     * @var string $value formatted display value
     * @var ?string $sub optional caption (e.g. "/min", "p95")
     * @var ?string $tone "neutral"|"warn"|"danger" — drives value color
     */
    $tone ??= 'neutral';
    $valueCls = match ($tone) {
        'warn' => 'text-amber-700 dark:text-amber-300',
        'danger' => 'text-red-700 dark:text-red-300',
        default => 'text-gray-900 dark:text-gray-100',
    };
@endphp
<div>
    <p class="truncate text-sm font-medium text-gray-500 dark:text-gray-300">{{ $label }}</p>
    <p class="mt-1 flex items-baseline gap-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $valueCls }}">
        <span class="truncate">{{ $value }}</span>
        @if(! empty($sub))
            <span class="text-sm font-normal text-gray-400 dark:text-gray-400">{{ $sub }}</span>
        @endif
    </p>
</div>
