@php
    /**
     * Dashboard workspace — left sidebar nav + section panes.
     *
     * Sections:
     *   Overview (default) — throughput hero, mission-grid summary cards,
     *                        and the full queues tables, stacked.
     *   Pending            — in-flight + pending-now + delayed tables.
     *   Completed          — full completed-jobs list with filters.
     *   Batches            — batch list (only when batches enabled).
     *   Failed             — full failed-jobs list with filters + bulk-retry.
     *   Silenced           — silenced classes / patterns (only when any set).
     *   Classes            — per-class 24h volume / runtime / p95 / max.
     *   Schedule           — scheduler insights panel (only when enabled).
     *   Alert rules        — read-only listing of configured detector thresholds.
     *
     * The active section persists in `window.location.hash` (`#qi-overview`,
     * `#qi-pending`, …) so refreshes and bookmarks land back where the user
     * left off; the sidebar nav-item buttons and the mission-grid "See all
     * N →" cards both drive the `tab` state through `setTab`.
     *
     * Each pane renders from its own partial in `partials/tabs/pane-*.blade.php`;
     * see those files for the per-pane required-scope-vars contracts.
     */

    $hasPendingAny = ($pendingEnabled ?? false) && (count($inFlightRows) + count($pendingRows) + count($delayedRows)) > 0;
    // Schedule observability is gated on the package config flag; the
    // child Livewire panel handles the per-user `viewScheduleInsights`
    // Gate at mount time so the nav item can stay route-side cheap.
    $scheduleEnabled = \SanderMuller\QueueInsights\Support\Config::bool('scheduler.enabled', false);
@endphp

<div x-data="{
        tab: 'overview',
        mobileNav: false,
        // Sections that may be conditionally hidden — if the hash points at
        // one of these and the section isn't rendered, fall back to overview
        // so the operator doesn't land on an empty x-show pane.
        conditional: {
            pending: {{ ($pendingEnabled ?? false) ? 'true' : 'false' }},
            batches: {{ ($batchesEnabled ?? false) ? 'true' : 'false' }},
            silenced: {{ (count($silencedClasses ?? []) + count($silencedPatterns ?? [])) > 0 ? 'true' : 'false' }},
            schedule: {{ $scheduleEnabled ? 'true' : 'false' }},
        },
        setTab(name) {
            const target = (name in this.conditional && ! this.conditional[name]) ? 'overview' : name;
            this.tab = target;
            this.mobileNav = false;
            history.replaceState(null, '', '#qi-' + target);
        },
        readHash() {
            const hash = window.location.hash || '';
            const m = hash.match(/^#qi-(overview|pending|batches|completed|failed|classes|silenced|schedule|alerts)$/);
            if (m) { this.setTab(m[1]); return; }
            // Legacy `#qi-queues` links — the Queues section folded into the
            // Overview pane. Honour the bookmark: land on Overview, then
            // scroll to the queues tables where the standalone tab used to be.
            if (hash === '#qi-queues') {
                this.setTab('overview');
                this.$nextTick(() => document.getElementById('qi-overview-queues')?.scrollIntoView());
            }
        }
     }"
     x-init="readHash()"
     x-on:hashchange.window="readHash()"
     x-on:keydown.escape.window="mobileNav = false"
     class="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">

    {{-- Desktop sidebar — sticky so the section nav stays in reach while a
         long pane (the Overview stack, a paginated list) scrolls past. --}}
    <aside class="hidden lg:sticky lg:top-6 lg:block lg:w-48 lg:shrink-0">
        @include('queue-insights::partials.tabs.sidebar-nav')
    </aside>

    <div class="flex min-w-0 flex-1 flex-col gap-6">
        {{-- Mobile section nav — disclosure panel + hamburger. Required by
             the navigation guideline: a sidebar desktop nav still needs a
             mobile menu below `lg:`. --}}
        <div class="lg:hidden">
            <button type="button"
                    x-on:click="mobileNav = ! mobileNav"
                    x-bind:aria-expanded="mobileNav"
                    aria-controls="qi-mobile-nav"
                    class="inline-flex w-full items-center justify-between gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-900 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10">
                <span class="flex items-center gap-2">
                    <svg class="size-5 text-gray-500 dark:text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M2 5.75A.75.75 0 0 1 2.75 5h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 5.75Zm0 4.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 4.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/>
                    </svg>
                    Sections
                </span>
                <span class="text-xs font-normal capitalize text-gray-400 dark:text-gray-500" x-text="tab"></span>
            </button>
            <div id="qi-mobile-nav"
                 x-show="mobileNav"
                 x-cloak
                 x-transition.origin.top
                 x-on:click.outside="mobileNav = false"
                 class="mt-2 rounded-lg bg-white p-2 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @include('queue-insights::partials.tabs.sidebar-nav')
            </div>
        </div>

        {{-- Overview — throughput hero + mission-grid cards + full queues
             tables, stacked. The persistent hero lives here (not above the
             workspace) so the trend reads as the lede of the Overview
             section rather than chrome the other panes have to scroll past. --}}
        <div x-show="tab === 'overview'" x-cloak class="flex flex-col gap-6">
            @include('queue-insights::partials.persistent-hero')
            @include('queue-insights::partials.tabs.pane-overview')
            <section id="qi-overview-queues" class="flex flex-col gap-2">
                <h2 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Queues</h2>
                @include('queue-insights::partials.tabs.pane-queues')
            </section>
        </div>
        @if($pendingEnabled)
            <div x-show="tab === 'pending'" x-cloak>
                @include('queue-insights::partials.tabs.pane-pending')
            </div>
        @endif
        @if($batchesEnabled)
            <div id="qi-batches-section" x-show="tab === 'batches'" x-cloak>
                @include('queue-insights::partials.tabs.pane-batches')
            </div>
        @endif
        <div x-show="tab === 'completed'" x-cloak>
            @include('queue-insights::partials.tabs.pane-completed')
        </div>
        <div x-show="tab === 'failed'" x-cloak>
            @include('queue-insights::partials.tabs.pane-failed')
        </div>
        <div x-show="tab === 'classes'" x-cloak>
            @include('queue-insights::partials.tabs.pane-classes')
        </div>
        @if((count($silencedClasses ?? []) + count($silencedPatterns ?? [])) > 0)
            <div x-show="tab === 'silenced'" x-cloak>
                @include('queue-insights::partials.tabs.pane-silenced')
            </div>
        @endif
        @if($scheduleEnabled)
            <div x-show="tab === 'schedule'" x-cloak>
                <livewire:queue-insights-schedule-panel />
            </div>
        @endif
        <div x-show="tab === 'alerts'" x-cloak>
            <livewire:queue-insights-alert-rules-panel :scope-connection="$scopeConnection ?? null" />
        </div>
    </div>
</div>
