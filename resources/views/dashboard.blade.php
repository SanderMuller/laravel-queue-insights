<div @if(\SanderMuller\QueueInsights\Support\Config::bool('dashboard.polling', true)) wire:poll.10s @endif class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when a modal is open so AT
        users don't hear the background dashboard and keyboard focus can't
        escape the modal. MUST be a sibling of the modal (not an ancestor),
        or the modal itself would be inerted. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-6"
         x-data x-bind:inert="@js($hasOpenModal)">

        {{-- Connection-scope picker — pushed into the dark header above. --}}
        @if($connectionNav['should_render'])
            @php($activeTab = collect($connectionNav['tabs'])->firstWhere('active', true) ?? ($connectionNav['tabs'][0] ?? null))
            @push('header-scope')
                <span class="text-gray-600" aria-hidden="true">/</span>

                <div class="relative" x-data="{ open: false }">
                    <button type="button"
                            x-on:click="open = !open"
                            x-on:keydown.escape.window="open = false"
                            x-bind:aria-expanded="open"
                            aria-haspopup="menu"
                            class="inline-flex items-center gap-2 rounded-lg bg-white/5 px-3 py-1.5 text-sm font-medium text-white ring-1 ring-inset ring-white/10 transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">
                        <span class="text-xs uppercase tracking-wide text-gray-400">Connection</span>
                        @if($activeTab !== null)
                            @if($activeTab['name'] === null)
                                <span>{{ $activeTab['label'] }}</span>
                            @else
                                <code class="font-mono">{{ $activeTab['label'] }}</code>
                            @endif
                        @endif
                        <svg class="size-4 text-gray-400 transition" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                    </button>
                    <div x-show="open" x-cloak
                         x-transition.origin.top
                         x-on:click.outside="open = false"
                         role="menu"
                         aria-label="Connection scope"
                         class="absolute left-0 top-full z-20 mt-2 w-56 overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                        <ul role="list" class="py-1 text-sm">
                            @foreach($connectionNav['tabs'] as $tab)
                                <li>
                                    <a href="{{ $tab['url'] }}"
                                       wire:navigate
                                       @if($tab['tooltip'] !== null) title="{{ $tab['tooltip'] }}" @endif
                                       role="menuitemradio"
                                       aria-checked="{{ $tab['active'] ? 'true' : 'false' }}"
                                       @class([
                                           'flex items-center gap-2 px-3 py-1.5 transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-emerald-500',
                                           'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' => $tab['active'],
                                           'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800' => ! $tab['active'],
                                       ])>
                                        @if($tab['active'])
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.7a1 1 0 0 1 .03 1.4l-7.5 8a1 1 0 0 1-1.46 0l-3.5-3.75a1 1 0 1 1 1.46-1.36l2.77 2.97 6.77-7.23a1 1 0 0 1 1.43-.03Z" clip-rule="evenodd"/></svg>
                                        @else
                                            <span class="size-4" aria-hidden="true"></span>
                                        @endif
                                        @if($tab['name'] === null)
                                            <span>{{ $tab['label'] }}</span>
                                        @else
                                            <code class="font-mono text-xs">{{ $tab['label'] }}</code>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endpush
        @endif

        <x-queue-insights::flash-banner/>

        @include('queue-insights::partials.snapshot-watchdog-banner')

        @include('queue-insights::partials.alerts-strip')

        {{-- Inline scope strip — visible across every tab whenever the global
             class scope (`?ck=`) or queue scope (`?qk=`) is set. Reads as a
             sentence rather than a discrete UI surface so it stays subtle when
             present and disappears entirely when neither scope is active. --}}
        @if($selectedClass !== null || $selectedQueue !== '')
            @php($scopedClassShort = $selectedClass !== null && str_contains($selectedClass, '\\')
                ? substr($selectedClass, strrpos($selectedClass, '\\') + 1)
                : $selectedClass)
            <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Filtering by</span>
                @if($selectedQueue !== '')
                    <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
                        <span class="text-gray-500 dark:text-gray-400">queue</span>
                        <code class="font-mono font-medium text-gray-900 dark:text-gray-100" title="{{ $selectedQueueConnection }} / {{ $selectedQueueName }}">{{ $selectedQueueName }}</code>
                        <button type="button" wire:click="clearSelectedQueue" class="-mr-0.5 text-gray-400 hover:text-gray-900 dark:hover:text-gray-100" aria-label="Clear queue scope">
                            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </span>
                @endif
                @if($selectedClass !== null && $selectedQueue !== '')
                    <span aria-hidden="true">·</span>
                @endif
                @if($selectedClass !== null)
                    <span class="inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-400/30">
                        <span class="text-emerald-600/80 dark:text-emerald-400/80">class</span>
                        <code class="font-mono font-medium" title="{{ $selectedClass }}">{{ $scopedClassShort }}</code>
                        <button type="button" wire:click="clearSelectedClass" class="-mr-0.5 hover:text-emerald-900 dark:hover:text-emerald-200" aria-label="Clear class scope">
                            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </span>
                @endif
            </p>
        @endif

        @include('queue-insights::partials.persistent-hero')

        @include('queue-insights::partials.tabs-workspace')

    </div>{{-- /#qi-dashboard-content --}}

    {{-- Modal stacking order: batch modal renders FIRST so item modals
        (details / failed / pending), declared after, sit visually on top
        when both are open. Opening an item from the batch modal preserves
        `expandedBatchId` so closing the item modal restores the batch view
        underneath — the pattern the chain detail uses inside a single
        modal, generalised across modals. --}}

    @if($batchesEnabled && $expandedBatchId !== '')
        <x-queue-insights::batch-modal :batch="$selectedBatch"/>
    @endif

    @if($selectedPayload !== null)
        <x-queue-insights::details-modal
            :payload="$selectedPayload"
            :payload-tab="$payloadTab"
            :capture-mode="$captureMode"
            :expanded-batch-id="$expandedBatchId"
            :chain-back-top="$chainBackTop"/>
    @endif

    @if($selectedFailed !== null)
        <x-queue-insights::failed-modal :failed="$selectedFailed" :can-retry="$canRetry" :expanded-batch-id="$expandedBatchId" :chain-back-top="$chainBackTop"/>
    @endif

    @if($pendingEnabled && $selectedPendingUuid !== null)
        <x-queue-insights::pending-modal :pending="$selectedPending" :expanded-batch-id="$expandedBatchId" :chain-back-top="$chainBackTop"/>
    @endif
</div>
