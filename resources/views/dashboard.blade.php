<div wire:poll.10s class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when a modal is open so AT
        users don't hear the background dashboard and keyboard focus can't
        escape the modal. MUST be a sibling of the modal (not an ancestor),
        or the modal itself would be inerted. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-6"
         x-data x-bind:inert="@js($hasOpenModal)">

        <x-queue-insights::flash-banner/>

        @php
            $totalDepth = array_sum(array_map(fn ($q): int => is_numeric($q['depth']) ? (int) $q['depth'] : 0, $queues));
            $totalInFlight = array_sum(array_map(fn ($q): int => is_numeric($q['inflight'] ?? null) ? (int) $q['inflight'] : 0, $queues));
            $atRisk = array_values(array_filter($queues, fn ($q) => $q['error'] || $q['stale']));
            $healthy = array_values(array_filter($queues, fn ($q) => ! $q['error'] && ! $q['stale']));
            $sortedQueues = array_merge($atRisk, $healthy);

            // Top-N deepest queues — pad the Overview "Queues" card preview
            // when the at-risk list alone doesn't fill it.
            $deepest = $queues;
            usort($deepest, function ($a, $b) {
                $ad = is_numeric($a['depth']) ? (int) $a['depth'] : 0;
                $bd = is_numeric($b['depth']) ? (int) $b['depth'] : 0;
                return $bd <=> $ad;
            });

            $queuePreview = $atRisk;
            if (count($queuePreview) < 5) {
                foreach ($deepest as $q) {
                    $dup = false;
                    foreach ($queuePreview as $a) {
                        if ($a['queue'] === $q['queue'] && $a['connection'] === $q['connection']) { $dup = true; break; }
                    }
                    if (! $dup) $queuePreview[] = $q;
                    if (count($queuePreview) >= 5) break;
                }
            }
            $queuePreview = array_slice($queuePreview, 0, 5);

            // Pending preview — in-flight first (tagged so the dot pulses),
            // then pending-now, then delayed. Capped at 5.
            $pendingPreview = [];
            foreach ($inFlightRows as $r) { $pendingPreview[] = $r + ['_isInFlight' => true]; }
            foreach ($pendingRows as $r) { $pendingPreview[] = $r; }
            foreach ($delayedRows as $r) { $pendingPreview[] = $r; }
            $pendingPreview = array_slice($pendingPreview, 0, 5);

            $fmtMs = static function (?int $ms): string {
                if ($ms === null) return '—';
                if ($ms < 1000) return number_format($ms).'ms';
                if ($ms < 60_000) return number_format($ms / 1000, 1).'s';
                return number_format($ms / 60_000, 1).'m';
            };

            $activeBatchCount = $batchesEnabled ? count(array_filter($batches, fn ($b) => ! ($b['finished_at'] instanceof \Carbon\CarbonInterface) && ! ($b['cancelled_at'] instanceof \Carbon\CarbonInterface))) : 0;
            $hasPendingAny = ($pendingEnabled ?? false) && (count($inFlightRows) + count($pendingRows) + count($delayedRows)) > 0;
        @endphp

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
