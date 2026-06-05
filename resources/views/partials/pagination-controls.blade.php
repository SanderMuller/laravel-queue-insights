@php
    /**
     * Inline pagination footer rendered inside a list card. Hidden when the
     * list is empty (the empty-state card renders elsewhere); otherwise
     * shows the per-page dropdown alongside the prev/next buttons + range
     * label. Buttons are wired by name (`gotoCompletedPage` /
     * `gotoFailedPage`) so the same partial can drive Completed and Failed
     * tabs without coupling the partial to a specific Livewire component.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator $paginator
     * @var string                                     $gotoMethod      Livewire method name to call with the target page (no parens)
     * @var string                                     $perPageModel    Livewire prop name bound by the per-page dropdown (`completedPerPage` / `failedPerPage`)
     * @var list<int>                                  $perPageOptions  Whitelist of allowed per-page values
     */
    if ($paginator->total() === 0) {
        return;
    }

    $page = $paginator->currentPage();
    $totalPages = $paginator->lastPage();
    $perPage = $paginator->perPage();
    $rangeStart = $paginator->firstItem() ?? 0;
    $rangeEnd = $paginator->lastItem() ?? 0;
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);
    $hasMultiplePages = $paginator->hasPages();
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-950/5 dark:border-white/10 px-4 py-2 text-xs">
    <div class="flex items-center gap-3">
        <p class="text-gray-500 dark:text-gray-300 tabular-nums">
            Showing <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($rangeStart) }}</span>
            –<span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($rangeEnd) }}</span>
            of <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($paginator->total()) }}</span>
        </p>
        <label class="flex items-center gap-1.5 text-gray-500 dark:text-gray-300">
            <span>Per page</span>
            {{-- `.number` modifier coerces the `<option value="50">` string the
                 browser sends to int 50 before Livewire writes it onto the
                 typed `int` prop. Without this, some Livewire 3.x point
                 versions 419 on type mismatch, and the `in_array(..., [...], true)`
                 whitelist in the dashboard's `updated()` hook fails on
                 strict comparison. --}}
            <select wire:model.live.number="{{ $perPageModel }}"
                    class="rounded-lg bg-white dark:bg-gray-900 px-1.5 py-0.5 font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 tabular-nums focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
    </div>
    @if($hasMultiplePages)
        <div class="flex items-center gap-1">
            <button type="button"
                    wire:click="{{ $gotoMethod }}({{ $prev }})"
                    @disabled($paginator->onFirstPage())
                    class="inline-flex items-center gap-1 rounded-lg bg-white dark:bg-gray-900 px-2 py-1 font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-gray-100 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-chevron-left class="size-3"/>
                Prev
            </button>
            <span class="px-2 text-gray-500 dark:text-gray-300 tabular-nums">
                Page <span class="font-medium text-gray-700 dark:text-gray-300">{{ $page }}</span> of <span class="font-medium text-gray-700 dark:text-gray-300">{{ $totalPages }}</span>
            </span>
            <button type="button"
                    wire:click="{{ $gotoMethod }}({{ $next }})"
                    @disabled(! $paginator->hasMorePages())
                    class="inline-flex items-center gap-1 rounded-lg bg-white dark:bg-gray-900 px-2 py-1 font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-gray-100 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                Next
                <x-queue-insights::icon-chevron-right class="size-3"/>
            </button>
        </div>
    @endif
</div>
