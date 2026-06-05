<?php declare(strict_types=1);

// Laravel-Cloud-inspired redesign — structural locks for the blade changes
// the cloud look introduced. These are source-level assertions (mirroring the
// DarkModeRegressionGuardTest path resolution) so the LC card-header pattern
// can't silently regress out of the panes. Visual fidelity is verified with
// Playwright (see internal/specs/laravel-cloud-redesign.md); these pin the
// markup contracts those screenshots depend on.

function qiView(string $relative): string
{
    $path = realpath(__DIR__ . '/../../../resources/views/' . $relative);
    expect($path)->not->toBeFalse("view not found: {$relative}");

    return (string) file_get_contents((string) $path);
}

it('overview summary cards carry LC icon-square headers', function (): void {
    $blade = qiView('partials/tabs/pane-overview.blade.php');

    // Card-label icon squares are uniformly NEUTRAL (calm LC icon usage —
    // semantic colour lives in the badges/values, not the header glyph).
    $neutral = 'rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
    expect($blade)->toContain($neutral);

    // Four icon squares — one per summary card, all neutral.
    expect(substr_count($blade, 'flex size-7 shrink-0 items-center justify-center rounded-lg'))->toBe(4)
        ->and(substr_count($blade, $neutral))->toBe(4);
});

it('throughput, classes and schedule cards share the neutral icon-square header', function (): void {
    $neutral = 'rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';

    expect(qiView('components/throughput-sparkline.blade.php'))->toContain($neutral)
        ->and(qiView('components/job-classes-section.blade.php'))->toContain($neutral)
        ->and(qiView('livewire/schedule-insights-panel.blade.php'))->toContain($neutral)
        ->and(qiView('livewire/alert-rules-panel.blade.php'))->toContain($neutral);
});

it('the headline LIVE panel uses LC metric-card vertical dividers', function (): void {
    $blade = qiView('partials/persistent-hero.blade.php');

    // Columns 2 & 3 carry a hairline left border (dark-paired) and per-column
    // padding — the LC sub-stats-split-by-vertical-rules pattern.
    expect($blade)
        ->toContain('border-l border-gray-950/5 px-4 dark:border-white/10')
        ->toContain('border-l border-gray-950/5 pl-4 dark:border-white/10')
        // 4 bordered columns (cols 2 & 3 across the two stat rows).
        ->and(substr_count($blade, 'border-l border-gray-950/5'))->toBe(4);
});

it('the sidebar nav is icon-led with a per-section heroicon', function (): void {
    expect(qiView('partials/tabs/nav-item.blade.php'))
        ->toContain('<path fill-rule="evenodd" clip-rule="evenodd" d="{{ $icon }}"/>');

    // One icon path per section in the nav map.
    $nav = qiView('partials/tabs/sidebar-nav.blade.php');
    expect($nav)->toContain("'overview' =>")
        ->and($nav)->toContain("'schedule' =>")
        ->and($nav)->toContain("'alerts' =>")
        ->and($nav)->toContain("'icon' => \$navIcons['overview']");
});

it('the Horizon banner uses the calm LC notice tone, not the loud amber-100 block', function (): void {
    $blade = qiView('partials/horizon-not-running-banner.blade.php');

    expect($blade)
        ->toContain('bg-amber-50')
        ->toContain('ring-amber-600/20')
        ->not->toContain('bg-amber-100 p-3'); // the pre-redesign loud block
});
