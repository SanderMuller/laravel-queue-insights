<?php declare(strict_types=1);

use Illuminate\Support\Facades\View;

// Render the package layout view directly so the assertions exercise only
// the clock-toggle wiring without booting Livewire / Redis / auth.
function renderQiLayoutForClock(): string
{
    return View::make('queue-insights::layouts.app', ['slot' => ''])->render();
}

it('emits the clock-init FOIT head script and toggle when dashboard.clock.enabled is true', function (): void {
    config()->set('queue-insights.dashboard.clock.enabled', true);

    $html = renderQiLayoutForClock();

    expect($html)
        // Head script — single owner of localStorage['qi-clock'] +
        // `documentElement.dataset.clock`. Mirrors the theme-init pattern.
        ->toContain("var KEY = 'qi-clock'")
        ->toContain('root.dataset.clock = pref')
        ->toContain("'qi-clock-applied'")
        ->toContain("'qi-clock-change'")
        // The qi-time hydrator listens for the clock-applied event so a
        // toggle click flows through to every <time> element.
        ->toContain("addEventListener('qi-clock-applied'")
        // Toggle component is rendered in the header.
        ->toContain('aria-label="Clock format"')
        ->toContain('data-qi-clock-radio="12h"')
        ->toContain('data-qi-clock-radio="auto"')
        ->toContain('data-qi-clock-radio="24h"');
});

it('omits the clock-init head script and toggle when dashboard.clock.enabled is false', function (): void {
    config()->set('queue-insights.dashboard.clock.enabled', false);

    $html = renderQiLayoutForClock();

    expect($html)
        // Head-script-specific markers — these only appear when the
        // clock-init script is emitted.
        ->not->toContain("var KEY = 'qi-clock'")
        ->not->toContain('root.dataset.clock = pref')
        ->not->toContain("'qi-clock-change'")
        // Toggle component is not rendered in the header.
        ->not->toContain('data-qi-clock-radio');
    // Note: the qi-time hydrator's `qi-clock-applied` listener is emitted
    // unconditionally (it's the locale-aware path for every <time> element).
    // It's harmless with the head script absent — the event never fires.
});

it('qi-time hydrator reads documentElement.dataset.clock and toggles hour12 accordingly', function (): void {
    // Hydrator runs unconditionally (it's the locale-aware path for every
    // <time data-qi-time>). The `readClockOpts` helper is the new entry
    // point — assert its presence + the three branches so a refactor that
    // accidentally drops one of them fails loudly.
    $html = renderQiLayoutForClock();

    expect($html)
        ->toContain('readClockOpts')
        ->toContain("document.documentElement.dataset.clock || 'auto'")
        ->toContain("if (pref === '12h') { opts.hour12 = true; }")
        ->toContain("else if (pref === '24h') { opts.hour12 = false; }");
});
