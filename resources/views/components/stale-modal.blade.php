@props([
    /**
     * Which selection stranded — drives the close action and the copy.
     * 'completed' / 'failed' / 'pending'.
     *
     * @var 'completed'|'failed'|'pending'
     */
    'kind' => 'completed',
])

@php
    // A row click set a selection id (completed stream id, failed_jobs row
    // id, or pending uuid) but the backing record has since aged out — the
    // completed stream entry was trimmed, the failed_jobs row was pruned or
    // retried away, or pending tracking is disabled. Without this modal the
    // click reads as a dead no-op (the real modal's @if gate stays false).
    // This is the lightweight feedback surface; the close action drops the
    // dangling id so the next poll is clean.
    $closeAction = match ($kind) {
        'failed' => 'closeFailed',
        'pending' => 'closePending',
        default => 'closePayload',
    };

    $heading = match ($kind) {
        'failed' => 'Failed job no longer available',
        'pending' => 'Job no longer tracked',
        default => 'Completed job no longer available',
    };

    $body = match ($kind) {
        'failed' => 'This failed job is no longer in failed_jobs — it was retried, forgotten, or pruned. Close this modal and check the Failed list.',
        'pending' => 'Pending tracking is disabled, so this job can no longer be inspected here. Close this modal and check the Completed / Failed lists for the result.',
        default => 'This completed job has aged out of the recent window, or its stream entry was trimmed. Close this modal and check the Completed list.',
    };
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-stale-modal-title"
     x-data
     {{-- Escape is bound on this element (NOT the window) and stops
         propagation. The stale modal stacks on top of the batch modal,
         and the batch modal binds its own `keydown.escape.window`
         handler. Without `stopPropagation`, one Escape keypress would
         close BOTH layers in lockstep — defeating the "close the item
         modal, return to the batch view" stacking pattern. `x-trap`
         keeps focus inside this dialog, so the bubbling event reaches
         our handler before it would otherwise climb to the window. --}}
     x-on:keydown.escape="$event.stopPropagation(); $wire.{{ $closeAction }}()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="{{ $closeAction }}">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-md overflow-auto rounded-2xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/10 dark:ring-white/10"
         @click.stop>
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <h3 id="qi-stale-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">Job</h3>
            <button type="button"
                    wire:click="{{ $closeAction }}"
                    aria-label="Close modal"
                    class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-close/>
            </button>
        </div>

        <div class="p-4">
            <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center dark:border-white/10">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $heading }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-300">{{ $body }}</p>
            </div>
        </div>
    </div>
</div>
