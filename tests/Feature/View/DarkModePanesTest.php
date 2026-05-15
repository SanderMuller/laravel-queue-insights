<?php declare(strict_types=1);

// Phase 3: tab panes. Each pane @includes row partials and / or
// components that have their own DashboardData scope contracts.
// Rendering a pane in isolation would require seeding the full
// shape, so we assert via source-file grep that the canonical dark
// utility classes landed on the pane's outermost chrome (cards,
// table headers, dividers, empty states).
//
// `@class` arrays + ternaries inside attribute strings still resolve
// correctly when the source contains the dark utility — Blade just
// concatenates strings. Grepping the source is equivalent guarantee
// to grepping the rendered HTML for the elements under test.

function paneSource(string $name): string
{
    $contents = file_get_contents(
        __DIR__ . "/../../../resources/views/partials/tabs/{$name}.blade.php"
    );

    return $contents === false ? '' : $contents;
}

function partialSource(string $name): string
{
    $contents = file_get_contents(
        __DIR__ . "/../../../resources/views/partials/{$name}.blade.php"
    );

    return $contents === false ? '' : $contents;
}

it('emits dark variants on pane-overview cards (queues / pending / completed / failed)', function (): void {
    $src = paneSource('pane-overview');

    expect($src)
        // Card surface + ring (both healthy and at-risk branches).
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        ->toContain('dark:ring-red-400/30')
        ->toContain('dark:ring-amber-400/30')
        // Card heading text.
        ->toContain('dark:text-gray-100')
        // Severity chips.
        ->toContain('dark:bg-red-900/40')
        ->toContain('dark:text-red-300')
        ->toContain('dark:bg-amber-900/40')
        ->toContain('dark:text-amber-300')
        ->toContain('dark:text-emerald-300')
        // Footer divider + see-all link.
        ->toContain('dark:border-white/10')
        ->toContain('dark:divide-white/10');
});

it('emits dark variants on pane-queues at-risk + healthy tables and empty state', function (): void {
    $src = paneSource('pane-queues');

    expect($src)
        // Empty-state dashed border.
        ->toContain('dark:border-white/10')
        ->toContain('dark:bg-white/10')
        // At-risk red ring + heading + divider.
        ->toContain('dark:ring-red-400/30')
        ->toContain('dark:text-red-300')
        ->toContain('dark:divide-red-400/20')
        // Healthy gray ring + heading.
        ->toContain('dark:ring-white/10')
        ->toContain('dark:divide-white/10');
});

it('emits dark variants on pane-batches table chrome and empty state', function (): void {
    $src = paneSource('pane-batches');

    expect($src)
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        ->toContain('dark:border-white/10')
        ->toContain('dark:divide-white/10')
        ->toContain('dark:text-gray-300');
});

it('emits dark variants on pane-completed table chrome', function (): void {
    $src = paneSource('pane-completed');

    expect($src)
        // Table chrome.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        ->toContain('dark:divide-white/10');
});

it('emits dark variants on pane-failed bulk-retry button branches', function (): void {
    $src = paneSource('pane-failed');

    expect($src)
        // "narrow to retry" pill.
        ->toContain('dark:bg-gray-800')
        ->toContain('dark:ring-white/10')
        // Bulk-retry button — both confirming + idle x-bind:class branches.
        ->toContain('dark:bg-red-500')
        ->toContain('dark:ring-red-400')
        ->toContain('dark:text-emerald-300')
        ->toContain('dark:ring-emerald-400/30')
        ->toContain('dark:hover:bg-emerald-900/40')
        // Empty state + table chrome.
        ->toContain('dark:border-white/10');
});

it('emits dark variants on the shared filter-form toolbar (filtered pill + clear button + fields)', function (): void {
    $src = partialSource('filter-form');

    expect($src)
        // "filtered" pill.
        ->toContain('dark:bg-emerald-900/40')
        ->toContain('dark:text-emerald-300')
        ->toContain('dark:ring-emerald-400/30')
        // Field chrome.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:text-gray-100')
        ->toContain('dark:ring-white/10')
        // Clear button hover.
        ->toContain('dark:hover:bg-white/5')
        ->toContain('dark:hover:text-gray-100');
});

it('emits dark variants on pane-pending in-flight (neutral) / pending (gray) / delayed (indigo) sub-tables', function (): void {
    $src = paneSource('pane-pending');

    expect($src)
        // Empty state.
        ->toContain('dark:border-white/10')
        // In-flight + Pending now share neutral chrome — liveness is
        // carried by the per-row radar pulse, not amber surface chrome.
        ->toContain('dark:ring-white/10')
        ->toContain('dark:divide-white/10')
        // Delayed (indigo).
        ->toContain('dark:text-indigo-300')
        ->toContain('dark:ring-indigo-400/30')
        ->toContain('dark:divide-indigo-400/20');
});

it('emits dark variants on pane-silenced roster + class chips + sub-tables', function (): void {
    $src = paneSource('pane-silenced');

    expect($src)
        // Section headings.
        ->toContain('dark:text-gray-300')
        // Class / pattern chips.
        ->toContain('dark:bg-gray-800')
        ->toContain('dark:ring-white/10')
        // Sub-table chrome.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:divide-white/10')
        // Empty states.
        ->toContain('dark:border-white/10');
});
