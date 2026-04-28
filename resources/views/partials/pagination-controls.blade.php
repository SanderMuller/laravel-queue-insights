@php
    /**
     * Inline pagination footer rendered inside a list card. Hidden when the
     * list fits a single page so empty / short lists don't get a noisy "1 of 1"
     * row. Buttons are wired by name (`gotoCompletedPage` / `gotoFailedPage`)
     * so the same partial can drive Completed and Failed tabs.
     *
     * @var int    $page
     * @var int    $totalPages
     * @var int    $total
     * @var int    $perPage
     * @var string $gotoMethod  Livewire method name to call with the target page
     */
    $page = max(1, (int) $page);
    $totalPages = max(1, (int) $totalPages);

    if ($totalPages <= 1) {
        return;
    }

    $rangeStart = ($page - 1) * $perPage + 1;
    $rangeEnd = min($total, $page * $perPage);
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);
@endphp

<div class="flex items-center justify-between gap-3 border-t border-gray-950/5 px-4 py-2 text-xs">
    <p class="text-gray-500 tabular-nums">
        Showing <span class="font-medium text-gray-700">{{ number_format($rangeStart) }}</span>
        –<span class="font-medium text-gray-700">{{ number_format($rangeEnd) }}</span>
        of <span class="font-medium text-gray-700">{{ number_format($total) }}</span>
    </p>
    <div class="flex items-center gap-1">
        <button type="button"
                wire:click="{{ $gotoMethod }}({{ $prev }})"
                @disabled($page <= 1)
                class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-950/[0.03] hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
            </svg>
            Prev
        </button>
        <span class="px-2 text-gray-500 tabular-nums">
            Page <span class="font-medium text-gray-700">{{ $page }}</span> of <span class="font-medium text-gray-700">{{ $totalPages }}</span>
        </span>
        <button type="button"
                wire:click="{{ $gotoMethod }}({{ $next }})"
                @disabled($page >= $totalPages)
                class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-950/[0.03] hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
            Next
            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
            </svg>
        </button>
    </div>
</div>
