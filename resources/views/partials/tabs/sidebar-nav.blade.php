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

    // Per-section heroicons (20×20 solid, single path, evenodd). Icon-led nav
    // is the Laravel Cloud pattern; the icons carry the visual hierarchy so the
    // Jobs group no longer needs indentation.
    $navIcons = [
        'overview' => 'M9.293 2.293a1 1 0 0 1 1.414 0l7 7A1 1 0 0 1 17 11h-1v6a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-3a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-6H3a1 1 0 0 1-.707-1.707l7-7Z',
        'pending' => 'M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .27.144.518.378.651l3 1.714a.75.75 0 0 0 .744-1.302L10.75 9.566V5Z',
        'completed' => 'M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z',
        'batches' => 'M2 4.25A2.25 2.25 0 0 1 4.25 2h2.5A2.25 2.25 0 0 1 9 4.25v2.5A2.25 2.25 0 0 1 6.75 9h-2.5A2.25 2.25 0 0 1 2 6.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 2h2.5A2.25 2.25 0 0 1 18 4.25v2.5A2.25 2.25 0 0 1 15.75 9h-2.5A2.25 2.25 0 0 1 11 6.75v-2.5Zm-9 9A2.25 2.25 0 0 1 4.25 11h2.5A2.25 2.25 0 0 1 9 13.25v2.5A2.25 2.25 0 0 1 6.75 18h-2.5A2.25 2.25 0 0 1 2 15.75v-2.5Zm9 0A2.25 2.25 0 0 1 13.25 11h2.5A2.25 2.25 0 0 1 18 13.25v2.5A2.25 2.25 0 0 1 15.75 18h-2.5A2.25 2.25 0 0 1 11 15.75v-2.5Z',
        'failed' => 'M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
        'silenced' => 'M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM6.75 9.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z',
        'classes' => 'M5 3a2 2 0 0 0-2 2v2.764a2 2 0 0 0 .586 1.414l8.5 8.5a2 2 0 0 0 2.828 0l2.764-2.764a2 2 0 0 0 0-2.828l-8.5-8.5A2 2 0 0 0 7.764 3H5Zm1.5 4a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
        'schedule' => 'M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1.5 5.5a.75.75 0 0 0 0 1.5h11.5a.75.75 0 0 0 0-1.5H4.25Z',
        'alerts' => 'M10 2a6 6 0 0 0-6 6c0 1.887-.454 3.665-1.257 5.234a.75.75 0 0 0 .515 1.076 32.91 32.91 0 0 0 3.256.508 3.5 3.5 0 0 0 6.972 0 32.903 32.903 0 0 0 3.256-.508.75.75 0 0 0 .515-1.076A11.448 11.448 0 0 1 16 8a6 6 0 0 0-6-6Zm0 14.5a2 2 0 0 1-1.95-1.557 33.54 33.54 0 0 0 3.9 0A2 2 0 0 1 10 16.5Z',
    ];
@endphp
<nav class="flex flex-col gap-0.5" aria-label="Dashboard sections">
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'overview', 'label' => 'Overview', 'badge' => null, 'icon' => $navIcons['overview']])

    <p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Jobs</p>
    @if($pendingEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'pending', 'label' => 'Pending', 'badge' => $pendingCount, 'icon' => $navIcons['pending']])
    @endif
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'completed', 'label' => 'Completed', 'badge' => $completedTotal ?? count($completedRows), 'icon' => $navIcons['completed']])
    @if($batchesEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'batches', 'label' => 'Batches', 'badge' => count($batches), 'icon' => $navIcons['batches']])
    @endif
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'failed', 'label' => 'Failed', 'badge' => $failedTotal ?? count($failedRows), 'icon' => $navIcons['failed']])
    @if($silencedCount > 0)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'silenced', 'label' => 'Silenced', 'badge' => $silencedCount, 'icon' => $navIcons['silenced']])
    @endif

    <div class="pt-2"></div>
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'classes', 'label' => 'Classes', 'badge' => count($classes), 'icon' => $navIcons['classes']])
    @if($scheduleEnabled)
        @include('queue-insights::partials.tabs.nav-item', ['name' => 'schedule', 'label' => 'Schedule', 'badge' => null, 'icon' => $navIcons['schedule']])
    @endif

    <hr class="my-2 border-gray-950/5 dark:border-white/10">
    @include('queue-insights::partials.tabs.nav-item', ['name' => 'alerts', 'label' => 'Alert rules', 'badge' => null, 'icon' => $navIcons['alerts']])
</nav>
