<div @if(\SanderMuller\QueueInsights\Support\Config::bool('dashboard.polling', true)) wire:poll.10s @endif class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when a modal is open so AT
        users don't hear the background dashboard and keyboard focus can't
        escape the modal. MUST be a sibling of the modal (not an ancestor),
        or the modal itself would be inerted. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-6"
         x-data x-bind:inert="@js($hasOpenModal)">

        <x-queue-insights::flash-banner/>

        @include('queue-insights::partials.snapshot-watchdog-banner')

        @include('queue-insights::partials.alerts-strip')

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
