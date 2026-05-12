{{-- Flash banner for server-side action feedback (retry happy path / errors).
    Reads `qi.retry.ok` (success — emerald) and `qi.retry.error` (failure — red)
    from session. Auto-dismisses after 5s via Alpine, or manual via the close
    button. Lives inside the dashboard component so wire:poll re-renders pick
    up new flashes without a full page reload. --}}
@php
    $okMessage = session('qi.retry.ok');
    $errorMessage = session('qi.retry.error');
@endphp

@if($okMessage || $errorMessage)
    <div x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)" x-cloak
         role="status" aria-live="polite"
         @class([
             'mb-4 flex items-start gap-3 rounded-lg p-3 text-sm ring-1 ring-inset transition',
             'bg-emerald-50 text-emerald-900 ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-200 dark:ring-emerald-400/30' => $okMessage,
             'bg-red-50 text-red-900 ring-red-600/20 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-400/30' => $errorMessage,
         ])>
        @if($okMessage)
            <svg class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/>
            </svg>
            <p class="min-w-0 flex-1">{{ $okMessage }}</p>
        @else
            <x-queue-insights::icon-error-circle class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400"/>
            <p class="min-w-0 flex-1">{{ $errorMessage }}</p>
        @endif

        <button type="button" x-on:click="shown = false"
                aria-label="Dismiss"
                class="shrink-0 rounded p-0.5 text-gray-400 hover:bg-gray-950/5 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200">
            <x-queue-insights::icon-close class="size-3.5"/>
        </button>
    </div>
@endif
