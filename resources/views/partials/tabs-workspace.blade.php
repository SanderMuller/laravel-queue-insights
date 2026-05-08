@php
    /**
     * Tabbed dashboard workspace. Tabs:
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
     *   Classes             — per-class 24h volume / runtime / p95 / max with
     *                          silenced badge for classes in `queue-insights.silenced`.
     *   Alert rules         — read-only listing of configured detector thresholds.
     *
     * Tab persists in `window.location.hash` (`#qi-overview`, `#qi-queues`, …)
     * so refreshes and bookmarks land back where the user left off, and the
     * mission-grid card buttons just set the hash to drive tab changes.
     *
     * Each pane renders from its own partial in `partials/tabs/pane-*.blade.php`;
     * see those files for the per-pane required-scope-vars contracts.
     */

    $hasPendingAny = ($pendingEnabled ?? false) && (count($inFlightRows) + count($pendingRows) + count($delayedRows)) > 0;
    // Schedule observability is gated on the package config flag; the
    // child Livewire panel handles the per-user `viewScheduleInsights`
    // Gate at mount time so the tab button can stay route-side cheap.
    $scheduleEnabled = \SanderMuller\QueueInsights\Support\Config::bool('scheduler.enabled', false);
@endphp

<div x-data="{ tab: 'overview' }"
     x-init="
        // Tabs that may be conditionally hidden — if the hash points at
        // one of these and the tab isn't rendered, fall back to overview
        // so the operator doesn't land on an empty x-show pane.
        const conditional = {
            pending: {{ ($pendingEnabled ?? false) ? 'true' : 'false' }},
            batches: {{ ($batchesEnabled ?? false) ? 'true' : 'false' }},
            silenced: {{ (count($silencedClasses ?? []) + count($silencedPatterns ?? [])) > 0 ? 'true' : 'false' }},
            schedule: {{ $scheduleEnabled ? 'true' : 'false' }},
        };
        const apply = () => {
            const m = (window.location.hash || '').match(/^#qi-(overview|queues|pending|batches|completed|failed|classes|silenced|schedule|alerts)$/);
            if (! m) return;
            const target = m[1];
            tab = (target in conditional && ! conditional[target]) ? 'overview' : target;
        };
        apply();
        window.addEventListener('hashchange', apply);
     "
     class="flex flex-col gap-4">

    {{-- Sticky tab strip — bleeds into the page padding so the underline runs full-width.
        Every tab badge is the plain integer total in muted gray; urgency is surfaced inside
        each tab's content (ring colours, status pills) rather than in the strip. --}}
    <div class="sticky top-0 z-10 -mx-6 border-b border-gray-950/5 bg-gray-50/90 px-6 backdrop-blur sm:-mx-8 sm:px-8 lg:-mx-10 lg:px-10 dark:border-white/10 dark:bg-gray-950/90">
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
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'classes', 'label' => 'Classes', 'badge' => count($classes)])
            @if((count($silencedClasses ?? []) + count($silencedPatterns ?? [])) > 0)
                @include('queue-insights::partials.tabs.tab-button', ['name' => 'silenced', 'label' => 'Silenced', 'badge' => count($silencedClasses) + count($silencedPatterns ?? [])])
            @endif
            @if($scheduleEnabled)
                @include('queue-insights::partials.tabs.tab-button', ['name' => 'schedule', 'label' => 'Schedule', 'badge' => null])
            @endif
            @include('queue-insights::partials.tabs.tab-button', ['name' => 'alerts', 'label' => 'Alert rules', 'badge' => null])
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
    <div x-show="tab==='classes'" x-cloak>
        @include('queue-insights::partials.tabs.pane-classes')
    </div>
    @if((count($silencedClasses ?? []) + count($silencedPatterns ?? [])) > 0)
        <div x-show="tab==='silenced'" x-cloak>
            @include('queue-insights::partials.tabs.pane-silenced')
        </div>
    @endif
    @if($scheduleEnabled)
        <div x-show="tab==='schedule'" x-cloak>
            <livewire:queue-insights-schedule-panel />
        </div>
    @endif
    <div x-show="tab==='alerts'" x-cloak>
        <livewire:queue-insights-alert-rules-panel :scope-connection="$scopeConnection ?? null" />
    </div>
</div>
