{{-- Failed-job modal header — shared across modal layout variants.
    Expects in scope: $failed, $canRetry, $expandedBatchId, $chainBackTop. --}}
<div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
    <div class="flex items-center gap-2">
        @if($expandedBatchId !== '')
            <button type="button"
                    x-show="view === 'job'"
                    wire:click="closeFailed"
                    aria-label="Back to batch"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-chevron-left class="size-3.5"/>
                <span>Back to batch</span>
            </button>
        @endif
        @include('queue-insights::partials.chain-back-button', ['frame' => $chainBackTop])
        <button type="button"
                x-show="view === 'chain' || view === 'chain-detail'"
                x-cloak
                x-on:click="view = (view === 'chain-detail') ? 'chain' : 'job'"
                aria-label="Back"
                class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
            <x-queue-insights::icon-chevron-left class="size-3.5"/>
            <span>Back</span>
        </button>
        <span x-show="view === 'job'" class="inline-flex size-6 items-center justify-center rounded-md bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30">
            <x-queue-insights::icon-error-circle class="size-3.5"/>
        </span>
        <h3 id="qi-failed-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            <span x-show="view === 'job'">Failed job</span>
            <span x-show="view === 'chain'" x-cloak>Chained jobs</span>
            <span x-show="view === 'chain-detail'" x-cloak>Chained job details</span>
        </h3>
    </div>
    <div class="flex items-center gap-1.5">
        {{-- Retry — gated, two-click confirm. Server-side `retryFailed`
            re-runs the gate + rate-limit; UI button visibility is a
            convenience guard. --}}
        @if($canRetry && ! empty($failed['uuid']))
            <div x-data="{ confirming: false, t: null }" x-on:click.outside="confirming = false">
                <button type="button"
                        x-bind:class="confirming
                            ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500 dark:bg-red-500 dark:ring-red-400 dark:hover:bg-red-400'
                            : 'bg-white dark:bg-gray-900 text-emerald-700 dark:text-emerald-300 ring-emerald-600/30 dark:ring-emerald-400/30 hover:bg-emerald-50 dark:hover:bg-emerald-900/40'"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                        x-on:click="
                            if (! confirming) {
                                confirming = true;
                                t = setTimeout(() => confirming = false, 2500);
                                return;
                            }
                            clearTimeout(t);
                            confirming = false;
                            $wire.retryFailed(@js($failed['uuid']));
                        ">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.22Z" clip-rule="evenodd"/>
                    </svg>
                    <span x-show="! confirming">Retry</span>
                    <span x-show="confirming" x-cloak>Confirm retry?</span>
                </button>
            </div>
        @endif

        {{-- Markdown export — convenient hand-off to an AI agent / tracker.
            Source text lives in the hidden <pre> below. --}}
        <x-queue-insights::copy-button
            target="qi-failed-markdown"
            label="Copy failure details as Markdown"
            text="Copy markdown"/>
        <button type="button"
                wire:click="closeFailed"
                aria-label="Close failed job modal"
                class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
            <x-queue-insights::icon-close/>
        </button>
    </div>
</div>
