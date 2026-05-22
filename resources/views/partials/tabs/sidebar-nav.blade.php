@php
    /**
     * Dashboard sidebar nav — vertical section switcher. Rendered twice per
     * page: once inside the desktop `<aside>`, once inside the mobile
     * disclosure panel. Drives the surrounding `x-data="{ tab }"` state via
     * the nav-item buttons; the muted per-section counts read straight off
     * the DashboardData scope vars.
     */
    $silencedCount = count($silencedClasses ?? []) + count($silencedPatterns ?? []);
    $pendingCount = count($inFlightRows) + count($pendingRows) + count($delayedRows);
@endphp
<nav class="flex flex-col gap-0.5" aria-label="Dashboard sections">
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'overview', 'label' => 'Overview', 'badge' => null])

    <p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Jobs</p>
    @if($pendingEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'pending', 'label' => 'Pending', 'badge' => $pendingCount, 'indent' => true])
    @endif
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'completed', 'label' => 'Completed', 'badge' => $completedTotal ?? count($completedRows), 'indent' => true])
    @if($batchesEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'batches', 'label' => 'Batches', 'badge' => count($batches), 'indent' => true])
    @endif
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'failed', 'label' => 'Failed', 'badge' => $failedTotal ?? count($failedRows), 'indent' => true])
    @if($silencedCount > 0)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'silenced', 'label' => 'Silenced', 'badge' => $silencedCount, 'indent' => true])
    @endif

    <div class="pt-2"></div>
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'classes', 'label' => 'Classes', 'badge' => count($classes)])
    @if($scheduleEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'schedule', 'label' => 'Schedule', 'badge' => null])
    @endif

    <hr class="my-2 border-gray-950/5 dark:border-white/10">
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'alerts', 'label' => 'Alert rules', 'badge' => null])
</nav>
