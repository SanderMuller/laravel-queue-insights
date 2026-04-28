@php
    /**
     * Tabbed dashboard workspace. Six tabs:
     *
     *   Overview (default)  — mission-grid summary cards. Each card shows a
     *                          handful of clickable preview rows that open the
     *                          same modal as the full tab, plus a "See all N →"
     *                          button that switches to the matching tab.
     *   Queues              — at-risk + healthy tables.
     *   Pending             — in-flight + pending-now + delayed tables.
     *   Batches             — batch list (only when batches enabled).
     *   Completed           — full completed-jobs list with filters.
     *   Failed              — full failed-jobs list with filters + bulk-retry.
     *
     * Tab persists in `window.location.hash` (`#qi-overview`, `#qi-queues`, …)
     * so refreshes and bookmarks land back where the user left off, and the
     * mission-grid card buttons just set the hash to drive tab changes.
     *
     * Each pane renders from its own partial in `partials/tabs/pane-*.blade.php`;
     * see those files for the per-pane required-scope-vars contracts.
     */

    $hasPendingAny = ($pendingEnabled ?? false) && (count($inFlightRows) + count($pendingRows) + count($delayedRows)) > 0;
@endphp

<div x-data="{ tab: 'overview' }"
     x-init="
        const apply = () => {
            const m = (window.location.hash || '').match(/^#qi-(overview|queues|pending|batches|completed|failed)$/);
            if (m) tab = m[1];
        };
        apply();
        window.addEventListener('hashchange', apply);
     "
     class="flex flex-col gap-4">

    {{-- Sticky tab strip — bleeds into the page padding so the underline runs full-width.
        Every tab badge is the plain integer total in muted gray; urgency is surfaced inside
        each tab's content (ring colours, status pills) rather than in the strip. --}}
    <div class="sticky top-0 z-10 -mx-6 border-b border-gray-950/5 bg-gray-50/90 px-6 backdrop-blur sm:-mx-8 sm:px-8 lg:-mx-10 lg:px-10">
        <nav class="-mb-px flex flex-wrap items-center gap-x-1" aria-label="Sections">
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'overview', 'label' => 'Overview', 'badge' => null])
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'queues', 'label' => 'Queues', 'badge' => count($queues)])
            @if($pendingEnabled)
                @include('queue-insights::partials.tabs.tab-button', ['name' => 'pending', 'label' => 'Pending', 'badge' => count($inFlightRows) + count($pendingRows) + count($delayedRows)])
            @endif
            @if($batchesEnabled)
                @include('queue-insights::partials.tabs.tab-button', ['name' => 'batches', 'label' => 'Batches', 'badge' => count($batches)])
            @endif
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'completed', 'label' => 'Completed', 'badge' => $completedTotal ?? count($completedRows)])
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'failed', 'label' => 'Failed', 'badge' => $failedTotal ?? count($failedRows)])
        </nav>
    </div>

    <div x-show="tab==='overview'" x-cloak>
        @include('queue-insights::partials.tabs.pane-overview')
    </div>
    <div x-show="tab==='queues'" x-cloak>
        @include('queue-insights::partials.tabs.pane-queues')
    </div>
    @if($pendingEnabled)
        <div x-show="tab==='pending'" x-cloak>
            @include('queue-insights::partials.tabs.pane-pending')
        </div>
    @endif
    @if($batchesEnabled)
        <div id="qi-batches-section" x-show="tab==='batches'" x-cloak>
            @include('queue-insights::partials.tabs.pane-batches')
        </div>
    @endif
    <div x-show="tab==='completed'" x-cloak>
        @include('queue-insights::partials.tabs.pane-completed')
    </div>
    <div x-show="tab==='failed'" x-cloak>
        @include('queue-insights::partials.tabs.pane-failed')
    </div>
</div>
