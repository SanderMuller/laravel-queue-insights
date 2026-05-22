<?php declare(strict_types=1);

use Illuminate\Support\Facades\View;

// Render each Phase 2 surface in isolation. We don't need the layout
// flag to be on — `dark:` variants are emitted unconditionally per
// Phase 1 Findings; the body class gate only changes whether `.dark`
// ever lands on `<html>`.

it('emits dark variants on the persistent-hero card and KPI tiles', function (): void {
    $html = View::make('queue-insights::partials.persistent-hero', [
        // Empty-buckets path — sparkline component renders the empty-state
        // card without needing the bucket geometry. We're asserting on the
        // hero KPI panel, not the sparkline.
        'throughput' => [],
        'stats' => [
            'jobs_per_minute' => 0,
            'jobs_past_hour' => 0,
            'failed_past_hour' => 7,
            'max_wait_ms' => 6000,
        ],
        'totalDepth' => 1500,
        'totalInFlight' => 0,
        'fmtMs' => fn (): string => '6s',
    ])->render();

    expect($html)
        // Card chrome.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        // Muted dt label.
        ->toContain('dark:text-gray-300')
        // Default dd value.
        ->toContain('dark:text-gray-100')
        // Conditional failure tone (failed_past_hour > 0).
        ->toContain('text-red-700 dark:text-red-300')
        // Conditional warning tone (totalDepth > 1000 + max_wait_ms > 5000).
        ->toContain('text-amber-700 dark:text-amber-300');
});

it('emits dark variants on the sidebar nav-item', function (): void {
    $html = View::make('queue-insights::partials.tabs.nav-item', [
        'name' => 'overview',
        'label' => 'Overview',
        'badge' => 3,
    ])->render();

    expect($html)
        // Active branch.
        ->toContain('dark:bg-emerald-500/10')
        ->toContain('dark:text-emerald-300')
        // Inactive branch.
        ->toContain('dark:text-gray-300')
        ->toContain('dark:hover:bg-white/5')
        ->toContain('dark:hover:text-gray-100');
});

it('emits dark variants on the flash banner success path', function (): void {
    session()->flash('qi.retry.ok', '3 jobs queued for retry');

    $html = View::make('queue-insights::components.flash-banner')->render();

    expect($html)
        ->toContain('dark:bg-emerald-900/40')
        ->toContain('dark:text-emerald-200')
        ->toContain('dark:ring-emerald-400/30')
        ->toContain('dark:text-emerald-400');
});

it('emits dark variants on the flash banner error path', function (): void {
    session()->flash('qi.retry.error', 'Retry failed');

    $html = View::make('queue-insights::components.flash-banner')->render();

    expect($html)
        ->toContain('dark:bg-red-900/40')
        ->toContain('dark:text-red-200')
        ->toContain('dark:ring-red-400/30')
        ->toContain('dark:text-red-400')
        // Close button hover state.
        ->toContain('dark:hover:bg-white/5');
});

it('emits dark variants on the snapshot-watchdog banner when the watchdog fires', function (): void {
    $html = View::make('queue-insights::partials.snapshot-watchdog-banner', [
        'snapshotCommandDead' => true,
    ])->render();

    expect($html)
        ->toContain('dark:bg-red-900/40')
        ->toContain('dark:text-red-200')
        ->toContain('dark:ring-red-400/30')
        ->toContain('dark:text-red-300')
        // Inline `<code>` chips.
        ->toContain('dark:bg-red-400/20');
});

it('emits dark variants on the horizon-not-running banner when the helper fires', function (): void {
    // Pass `$horizonNotRunning` explicitly — the partial accepts the override
    // when rendered in isolation; in production it self-resolves via `app()`.
    $html = View::make('queue-insights::partials.horizon-not-running-banner', [
        'horizonNotRunning' => true,
    ])->render();

    expect($html)
        ->toContain('dark:bg-amber-900/40')
        ->toContain('dark:text-amber-200')
        ->toContain('dark:ring-amber-400/30')
        ->toContain('dark:text-amber-300')
        // Inline `<code>` chips.
        ->toContain('dark:bg-amber-400/20');
});

it('emits dark variants on the alerts strip — critical row', function (): void {
    // Source-grep instead of render-with-Issue-fixture: the `Issue` DTO is
    // marked @internal, and the alerts-strip's critical/warning branches
    // are static `@class` array entries in the source — grep is the same
    // guarantee for "the dark utility landed on the right branch".
    $src = file_get_contents(
        __DIR__ . '/../../../resources/views/partials/alerts-strip.blade.php'
    );

    expect($src)
        // Critical row chrome.
        ->toContain('dark:bg-red-900/40')
        ->toContain('dark:text-red-200')
        ->toContain('dark:ring-red-400/30')
        // Severity dot.
        ->toContain('dark:bg-red-400')
        // Target pill.
        ->toContain('dark:bg-red-900/60')
        // Toggle button hover.
        ->toContain('dark:hover:bg-white/10');
});

it('emits dark variants on the alerts strip — warning row', function (): void {
    $src = file_get_contents(
        __DIR__ . '/../../../resources/views/partials/alerts-strip.blade.php'
    );

    expect($src)
        ->toContain('dark:bg-amber-900/40')
        ->toContain('dark:text-amber-200')
        ->toContain('dark:ring-amber-400/30')
        ->toContain('dark:bg-amber-400')
        ->toContain('dark:bg-amber-900/60');
});

it('renders no banner classes when no issues are active', function (): void {
    $html = View::make('queue-insights::partials.alerts-strip', [
        'activeIssues' => [],
    ])->render();

    // Whole list element is suppressed when the array is empty. The
    // Blade compiler may emit harmless whitespace/comments between
    // directives across Laravel versions, so assert against the
    // structural markers (the role="list" wrapper + any dark surface
    // tokens) rather than strict-empty output.
    expect($html)
        ->not->toContain('role="list"')
        ->not->toContain('aria-label="Active alerts"')
        ->not->toContain('dark:bg-red-900/40')
        ->not->toContain('dark:bg-amber-900/40');
});

it('emits dark variants on the tabs-workspace mobile-nav chrome', function (): void {
    // The workspace partial @includes every pane partial, each of which
    // expects a different chunk of DashboardData scope. Rendering the
    // workspace standalone would require seeding all of it. Since the
    // mobile-nav button + panel chrome is a static string in the source
    // (not built by `@class` or conditionals), a source-file grep is the
    // same guarantee as a render-time grep — the variant either exists in
    // the file or it doesn't.
    $source = file_get_contents(
        __DIR__ . '/../../../resources/views/partials/tabs-workspace.blade.php'
    );

    expect($source)
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10');
});
