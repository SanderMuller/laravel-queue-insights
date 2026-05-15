<?php declare(strict_types=1);

use Illuminate\Support\Facades\View;

// Phase 6: theme-toggle component + the layout integration that
// renders it inline in the header (NOT through the header-scope
// stack — see spec §2.3 for the ordering rationale).

it('renders three radio segments inside a radiogroup', function (): void {
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        ->toContain('role="radiogroup"')
        ->toContain('aria-label="Theme"')
        // Three buttons, one per preference.
        ->toContain('aria-label="Light theme"')
        ->toContain('aria-label="System theme"')
        ->toContain('aria-label="Dark theme"');

    // Three role="radio" buttons.
    $count = substr_count($html, 'role="radio"');
    expect($count)->toBe(3);
});

it('binds aria-checked to the resolved preference per segment', function (): void {
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        ->toContain("theme === 'light' ? 'true' : 'false'")
        ->toContain("theme === 'system' ? 'true' : 'false'")
        ->toContain("theme === 'dark' ? 'true' : 'false'");
});

it('reads initial preference from documentElement.dataset.theme', function (): void {
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        ->toContain("document.documentElement.dataset.theme || 'system'");
});

it('dispatches qi-theme-change with the chosen preference on click', function (): void {
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        ->toContain("setTheme('light')")
        ->toContain("setTheme('system')")
        ->toContain("setTheme('dark')")
        ->toContain("new CustomEvent('qi-theme-change', { detail: t })");
});

it('listens for qi-theme-applied on window to mirror system-pref changes', function (): void {
    $html = View::make('queue-insights::components.theme-toggle')->render();

    // Alpine `x-on:qi-theme-applied.window` registration. Alpine's
    // lifecycle tears this down on destroy (wire:navigate morph),
    // so listeners don't accumulate across navigations. The handler
    // reads `$event.detail.preference` (the new object-shaped detail)
    // with a fallback to 'system' for defensive null-handling.
    expect($html)
        ->toContain('x-on:qi-theme-applied.window')
        ->toContain('$event.detail.preference')
        ->toContain("|| 'system'");
});

it('roving tabindex puts only the checked radio in the Tab order', function (): void {
    // WAI-ARIA APG radiogroup: only the currently-selected radio should
    // be tab-reachable. Arrow keys move focus + selection between siblings.
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        // Per-button tabindex bound to whether that segment is checked.
        ->toContain("x-bind:tabindex=\"theme === 'light' ? 0 : -1\"")
        ->toContain("x-bind:tabindex=\"theme === 'system' ? 0 : -1\"")
        ->toContain("x-bind:tabindex=\"theme === 'dark' ? 0 : -1\"")
        // Radio identity attribute used by moveBy() to focus the next sibling.
        ->toContain('data-qi-theme-radio="light"')
        ->toContain('data-qi-theme-radio="system"')
        ->toContain('data-qi-theme-radio="dark"');
});

it('arrow keys cycle through radios and move focus + selection', function (): void {
    // Right / Down go forward, Left / Up go backward. moveBy(±1) wraps
    // (modular index) so dark→right wraps to light. Each move calls
    // setTheme() which dispatches qi-theme-change, then $nextTick focuses
    // the newly-checked radio.
    $html = View::make('queue-insights::components.theme-toggle')->render();

    expect($html)
        ->toContain('x-on:keydown.right.prevent="moveBy(1)"')
        ->toContain('x-on:keydown.down.prevent="moveBy(1)"')
        ->toContain('x-on:keydown.left.prevent="moveBy(-1)"')
        ->toContain('x-on:keydown.up.prevent="moveBy(-1)"')
        ->toContain("order: ['light', 'system', 'dark']")
        ->toContain('data-qi-theme-radio')
        ->toContain('moveBy(delta)');
});

it('renders the toggle in the layout header when the theme flag is on', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', true);

    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    expect($html)
        // Outer radiogroup landed in the header flex.
        ->toContain('role="radiogroup"')
        ->toContain('aria-label="Theme"')
        // Aurora live indicator still renders alongside the toggle.
        ->toContain('live · streaming');
});

it('omits the toggle from the layout when the theme flag is off', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', false);

    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    expect($html)
        ->not->toContain('aria-label="Theme"')
        ->not->toContain('aria-label="Light theme"')
        // Aurora live indicator still renders.
        ->toContain('live · streaming');
});

it('defaults the theme flag to true so existing hosts get the toggle on upgrade', function (): void {
    // The package config ships `enabled => env(QUEUE_INSIGHTS_DARK_MODE, true)`.
    // Hosts wanting opt-out set the env var to false. This assertion locks
    // the default-on stance (post-audit Phase 6 flip).
    expect(config('queue-insights.dashboard.theme.enabled'))->toBeTrue();
});

it('survives wire:navigate-style remount without duplicate listeners', function (): void {
    // Re-rendering the toggle simulates Alpine's destroy + re-init under
    // wire:navigate. Listener registration via `x-on:qi-theme-applied.window`
    // is Alpine-managed: each instance owns one listener; Alpine cleans up
    // on destroy via its internal teardown hook. Here we assert the
    // ATTRIBUTE form is present on every render — duplication would mean
    // multiple listener registrations stacking up at the document level
    // (a Livewire/Alpine bug, not a markup bug). String count gives us a
    // proxy: each render should have exactly one `x-on:qi-theme-applied.window`.
    $renders = [];
    for ($i = 0; $i < 5; ++$i) {
        $renders[] = View::make('queue-insights::components.theme-toggle')->render();
    }

    foreach ($renders as $r) {
        expect(substr_count($r, 'x-on:qi-theme-applied.window'))->toBe(1);
    }
});
