<div wire:poll.10s class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when a modal is open so AT
        users don't hear the background dashboard and keyboard focus can't
        escape the modal. MUST be a sibling of the modal (not an ancestor),
        or the modal itself would be inerted. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-6"
         x-data x-bind:inert="@js($hasOpenModal)">

        <x-queue-insights::flash-banner/>

        {{-- Persistent hero — sparkline (full card) alongside a 6-KPI panel.
            Always visible across tabs so the throughput trend is the last
            thing to fall off-screen. The sparkline component renders its
            own card chrome (ring + padding + axis labels), so we let it
            size itself rather than clamping its height. --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-queue-insights::throughput-sparkline :throughput="$throughput"/>
            </div>
            <dl aria-label="Headline stats" class="grid grid-cols-3 gap-x-4 gap-y-3 rounded-xl bg-white p-5 ring-1 ring-gray-950/5">
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">Jobs / min</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900">{{ number_format($stats['jobs_per_minute']) }}</dd>
                </div>
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">Past hour</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900">{{ number_format($stats['jobs_past_hour']) }}</dd>
                </div>
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">Failed / hr</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $stats['failed_past_hour'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($stats['failed_past_hour']) }}</dd>
                </div>
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">Backlog</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $totalDepth > 1000 ? 'text-amber-700' : 'text-gray-900' }}">{{ number_format($totalDepth) }}</dd>
                </div>
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">In-flight</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums text-gray-900">{{ number_format($totalInFlight) }}</dd>
                </div>
                <div>
                    <dt class="truncate text-xs font-medium text-gray-500">p95 wait</dt>
                    <dd class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $stats['max_wait_ms'] !== null && $stats['max_wait_ms'] > 5_000 ? 'text-amber-700' : 'text-gray-900' }}">{{ $fmtMs($stats['max_wait_ms']) }}</dd>
                </div>
            </dl>
        </div>

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
            :expanded-batch-id="$expandedBatchId"/>
    @endif

    @if($selectedFailed !== null)
        <x-queue-insights::failed-modal :failed="$selectedFailed" :can-retry="$canRetry" :expanded-batch-id="$expandedBatchId"/>
    @endif

    @if($pendingEnabled && $selectedPendingUuid !== null)
        <x-queue-insights::pending-modal :pending="$selectedPending" :expanded-batch-id="$expandedBatchId"/>
    @endif
</div>
