@php
    /**
     * @var string $label
     * @var string $value formatted display value
     * @var ?string $sub optional caption (e.g. "/min", "p95")
     * @var ?string $tone "neutral"|"warn"|"danger" — drives value color
     * @var ?string $title optional hover text (e.g. the exact count behind "11.9k")
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
    {{-- The caption wraps under the value in a narrow tile; the value itself never truncates. --}}
    <p class="mt-1 flex flex-wrap items-baseline gap-x-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $valueCls }}">
        <span class="whitespace-nowrap" @if(! empty($title)) title="{{ $title }}" @endif>{{ $value }}</span>
        @if(! empty($sub))
            <span class="text-sm font-normal text-gray-400 dark:text-gray-400">{{ $sub }}</span>
        @endif
    </p>
</div>
