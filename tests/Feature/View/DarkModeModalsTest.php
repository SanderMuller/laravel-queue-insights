<?php declare(strict_types=1);

// Phase 4: modals + payload internals. Each modal component takes a
// payload fixture from `RowEnricher::failed()` / `recentCompleted()`
// shape. Rendering one standalone is heavy (modal includes parent
// lineage row, batch chip, qi-time, structured-payload, stack-trace,
// chain-back-button — all with their own scope contracts). Source
// grep is the same guarantee for "the dark utility landed at the
// right element" since the dark variants are static strings in the
// source, not built by `@class` arrays.

function modalSource(string $name): string
{
    $contents = file_get_contents(
        __DIR__ . "/../../../resources/views/components/{$name}.blade.php"
    );

    return $contents === false ? '' : $contents;
}

it('emits dark variants on details-modal panel + two-column rail + tabs', function (): void {
    $src = modalSource('details-modal');

    expect($src)
        // Modal panel surface.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        // Sticky header divider + left-rail border.
        ->toContain('dark:border-white/10')
        // Metadata description-list dividers.
        ->toContain('dark:divide-white/10')
        // Stream-id chip background.
        ->toContain('dark:bg-white/10')
        // Tab strip background.
        ->toContain('dark:bg-white/10 p-0.5')
        // Attempts retry badge.
        ->toContain('dark:bg-amber-900/60 dark:text-amber-200')
        // Body text + secondary.
        ->toContain('dark:text-gray-100')
        ->toContain('dark:text-gray-300')
        // Capture-mode chip.
        ->toContain('capture: ');
});

it('emits dark variants on failed-modal two-column rail + retry button branches', function (): void {
    // The retry button + header chrome live in the shared
    // `partials/failed-modal-header` partial; the two-column body
    // stays in the component. Scan both so the guarantee holds.
    $headerPartial = file_get_contents(
        __DIR__ . '/../../../resources/views/partials/failed-modal-header.blade.php'
    );
    $src = modalSource('failed-modal') . ($headerPartial === false ? '' : $headerPartial);

    expect($src)
        // Red "Failed" badge in the left rail.
        ->toContain('dark:bg-red-900/40 dark:text-red-400 dark:ring-red-400/30')
        // Left-rail border + metadata list dividers.
        ->toContain('dark:border-white/10')
        ->toContain('dark:divide-white/10')
        // Retry button — confirming branch.
        ->toContain('dark:bg-red-500 dark:ring-red-400 dark:hover:bg-red-400')
        // Retry button — idle branch.
        ->toContain('dark:hover:bg-emerald-900/40')
        // Modal panel.
        ->toContain('dark:bg-gray-900')
        // Header icon (red square + alert).
        ->toContain('dark:text-red-400');
});

it('emits dark variants on batch-modal hero chrome (4 status branches) + progress bar', function (): void {
    $src = modalSource('batch-modal');

    expect($src)
        // Cancelled / failed-stop branch.
        ->toContain('dark:from-red-900/40 dark:ring-red-400/30')
        // Finished-clean branch.
        ->toContain('dark:from-emerald-900/40 dark:ring-emerald-400/30')
        // Has-failures-but-allowed branch.
        ->toContain('dark:from-amber-900/40 dark:ring-amber-400/30')
        // `to-white` becomes `dark:to-gray-900`.
        ->toContain('dark:to-gray-900')
        // Progress bar track.
        ->toContain('dark:bg-white/10')
        // Empty-state dashed border.
        ->toContain('dark:border-white/10');
});

it('emits dark variants on pending-modal hero (in-flight / delayed / pending) + empty state', function (): void {
    $src = modalSource('pending-modal');

    expect($src)
        // In-flight (amber).
        ->toContain('dark:from-amber-900/40 dark:ring-amber-400/30')
        // Delayed (indigo).
        ->toContain('dark:from-indigo-900/40 dark:ring-indigo-400/30')
        // Pending (gray).
        ->toContain('dark:from-gray-800 dark:ring-white/10')
        ->toContain('dark:to-gray-900')
        // Empty state (race-after-pickup).
        ->toContain('dark:border-white/10');
});

it('emits dark variants on nested-data recursive renderer (object / array / null branches)', function (): void {
    $src = modalSource('nested-data');

    expect($src)
        // Empty / max-depth marker.
        ->toContain('text-gray-400 dark:text-gray-400')
        // Container heading (purple).
        ->toContain('text-purple-700 dark:text-purple-300')
        // Scalar default value.
        ->toContain('text-gray-900 dark:text-gray-100')
        // Expand button.
        ->toContain('dark:bg-white/10')
        ->toContain('dark:hover:bg-white/20')
        // Nested-data recursion border.
        ->toContain('dark:border-white/10 dark:bg-white/5')
        // Top-level dl divider.
        ->toContain('dark:divide-white/10');
});

it('emits dark variants on serialized-properties (object / array / null / scalar)', function (): void {
    $src = modalSource('serialized-properties');

    expect($src)
        ->toContain('dark:divide-white/10')
        // Property-name dt.
        ->toContain('text-gray-600 dark:text-gray-300')
        // Object branch (purple).
        ->toContain('text-purple-700 dark:text-purple-300')
        // Array branch (blue).
        ->toContain('text-blue-700 dark:text-blue-300')
        // Default scalar.
        ->toContain('text-gray-900 dark:text-gray-100')
        // Expanded recursion surface.
        ->toContain('dark:border-white/10 dark:bg-white/5');
});

it('emits dark variants on stack-trace red header + frame chrome + vendor toggle', function (): void {
    $src = modalSource('stack-trace');

    expect($src)
        // Red header.
        ->toContain('dark:bg-red-900/40')
        ->toContain('dark:border-red-400/30')
        ->toContain('dark:text-red-300')
        ->toContain('dark:text-red-200')
        // Frame body surface.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:divide-white/10')
        // App vs vendor frame text.
        ->toContain('dark:text-gray-100')
        ->toContain('dark:text-emerald-300')
        // Vendor toggle.
        ->toContain('dark:hover:text-emerald-200');
});

it('emits dark variants on structured-payload section panels + tags + serialized-command pre', function (): void {
    $src = modalSource('structured-payload');

    expect($src)
        // Section panels.
        ->toContain('dark:bg-gray-900')
        ->toContain('dark:ring-white/10')
        // Tag chips.
        ->toContain('dark:bg-white/10 dark:text-gray-200')
        // Job-instance-unavailable amber banner.
        ->toContain('dark:bg-amber-900/40')
        ->toContain('dark:text-amber-200')
        // Serialized-command <pre>.
        ->toContain('dark:bg-white/5')
        // Truncated-value buttons.
        ->toContain('dark:hover:bg-white/20');
});

it('emits dark variants on job-classes-section table + summary + silenced badge', function (): void {
    $src = modalSource('job-classes-section');

    expect($src)
        // Summary hover.
        ->toContain('dark:hover:bg-white/5')
        // Selected-class chip.
        ->toContain('dark:bg-emerald-900/40 dark:text-emerald-300')
        // Empty state.
        ->toContain('dark:border-white/10')
        // Table chrome.
        ->toContain('dark:divide-white/10')
        // Selected row highlight.
        ->toContain('dark:bg-emerald-900/20')
        // Silenced badge.
        ->toContain('dark:bg-gray-800 dark:text-gray-300')
        // Failure-rate red.
        ->toContain('dark:text-red-400');
});

it('emits dark variants on throughput-sparkline empty + populated states', function (): void {
    $src = modalSource('throughput-sparkline');

    expect($src)
        // Empty-state card.
        ->toContain('dark:bg-gray-900 dark:ring-white/10 dark:text-gray-300')
        // Populated card heading + dl labels.
        ->toContain('dark:text-gray-100')
        ->toContain('dark:text-gray-300')
        // Failed-tone branch.
        ->toContain('dark:text-red-400')
        // x-axis labels.
        ->toContain('dark:text-gray-400');
});
