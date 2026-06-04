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

    // Each card header opens with a rounded icon square. Neutral squares
    // (Classes/Pending) pair bg-gray-100 with dark:bg-gray-800; the semantic
    // ones use emerald (Completed) and red (Failed).
    expect($blade)
        ->toContain('rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400')
        ->toContain('rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400')
        ->toContain('rounded-lg bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400');

    // Four icon squares — one per summary card.
    expect(substr_count($blade, 'flex size-7 shrink-0 items-center justify-center rounded-lg'))->toBe(4);
});

it('throughput, classes and schedule cards share the icon-square header language', function (): void {
    expect(qiView('components/throughput-sparkline.blade.php'))
        ->toContain('rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400');

    expect(qiView('components/job-classes-section.blade.php'))
        ->toContain('rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400');

    // Schedule panel: Hourly throughput (emerald) + Tasks (neutral).
    $schedule = qiView('livewire/schedule-insights-panel.blade.php');
    expect($schedule)
        ->toContain('rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400')
        ->toContain('rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400');

    // Alert rules: bell icon-header on the Alerting card.
    expect(qiView('livewire/alert-rules-panel.blade.php'))
        ->toContain('rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400');
});

it('the Horizon banner uses the calm LC notice tone, not the loud amber-100 block', function (): void {
    $blade = qiView('partials/horizon-not-running-banner.blade.php');

    expect($blade)
        ->toContain('bg-amber-50')
        ->toContain('ring-amber-600/20')
        ->not->toContain('bg-amber-100 p-3'); // the pre-redesign loud block
});
