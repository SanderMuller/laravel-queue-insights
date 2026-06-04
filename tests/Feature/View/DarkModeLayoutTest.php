<?php declare(strict_types=1);

use Illuminate\Support\Facades\View;

// Render the package layout view directly (no Livewire component, no Redis,
// no auth) so the assertions exercise only the layout edits made in Phase 1
// of internal/specs/dashboard-dark-mode.md. The slot is empty — none of the
// inner partials matter for these assertions.
function renderQiLayout(): string
{
    return View::make('queue-insights::layouts.app', ['slot' => ''])->render();
}

it('emits the FOIT head script and color-scheme meta when theme.enabled is true', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', true);

    $html = renderQiLayout();

    expect($html)
        ->toContain('<meta name="color-scheme" content="light dark">')
        ->toContain('localStorage.getItem(KEY)')
        ->toContain("matchMedia('(prefers-color-scheme: dark)')")
        ->toContain("'qi-theme-applied'")
        ->toContain("'qi-theme-change'")
        // Body picks up the dark surface classes.
        ->toContain('dark:bg-gray-950')
        ->toContain('dark:text-gray-100');
});

it('omits the FOIT head script and color-scheme meta when theme.enabled is false', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', false);

    $html = renderQiLayout();

    expect($html)
        ->not->toContain('color-scheme')
        // Theme-specific localStorage key. The clock-toggle FOIT script
        // (a sibling, independently gated) also reads localStorage, so the
        // generic `localStorage.getItem(KEY)` check isn't theme-specific.
        ->not->toContain("var KEY = 'qi-theme'")
        ->not->toContain("'qi-theme-applied'")
        ->not->toContain("'qi-theme-change'")
        // Body should NOT carry the dark surface classes when the flag is off.
        ->not->toContain('dark:bg-gray-950');
});

it('emits tailwind.config darkMode + safelist unconditionally', function (): void {
    // Rationale (see spec Findings, Phase 1): keeping `darkMode: "class"` set
    // even with the flag off is required so any `dark:` variant added during
    // the Phase 2-5 audit window stays inert (no `.dark` ancestor) instead
    // of falling through to Tailwind v3's default `media` mode, which would
    // expose half-themed surfaces on system-dark hosts.
    config()->set('queue-insights.dashboard.theme.enabled', false);
    expect(renderQiLayout())
        ->toContain("darkMode: 'class'")
        // Horizon-style palette: keys blue, strings amber, numbers / null /
        // booleans purple. Safelist drops the legacy green + orange entries.
        ->toContain("'dark:text-blue-300'")
        ->toContain("'dark:text-amber-300'")
        ->toContain("'dark:text-purple-300'");

    config()->set('queue-insights.dashboard.theme.enabled', true);
    expect(renderQiLayout())
        ->toContain("darkMode: 'class'")
        ->toContain("'dark:text-blue-300'");
});

it('emits dual-class JSON colorizer spans unconditionally', function (): void {
    // Inert without `.dark` ancestor — emitting always avoids re-rendering
    // the colorizer when the flag flips at runtime. Spec §3.4 Option A.
    config()->set('queue-insights.dashboard.theme.enabled', false);
    $html = renderQiLayout();

    expect($html)
        ->toContain('text-blue-700 dark:text-blue-300')
        ->toContain('text-amber-700 dark:text-amber-300')
        ->toContain('text-purple-700 dark:text-purple-300');
});

it('emits html.dark CSS overrides for the inline style block unconditionally', function (): void {
    // Selectors only match when `.dark` is on <html>, so emitting them with
    // the flag off is harmless. Avoids a second render pass when the flag
    // flips. Spec §3.3.
    config()->set('queue-insights.dashboard.theme.enabled', false);
    $html = renderQiLayout();

    expect($html)
        ->toContain('html.dark [data-qi-copy][data-qi-copied]')
        ->toContain('html.dark #qi-time-tooltip');
});

it('falls through to system when localStorage holds an unknown value', function (): void {
    // String-grep against the inline FOIT script — full JS execution is out
    // of scope for this feature suite. The branch under test is the
    // `(v === 'light' || v === 'dark') ? v : 'system'` ternary.
    config()->set('queue-insights.dashboard.theme.enabled', true);

    expect(renderQiLayout())
        ->toContain("(v === 'light' || v === 'dark') ? v : 'system'");
});

it('swallows localStorage throw on read and write', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', true);

    $html = renderQiLayout();

    // Read-side catch returns 'system'.
    expect($html)->toContain("} catch (e) { return 'system'; }");
    // Write-side catch is a no-op (Safari private mode + sandboxed iframes).
    expect($html)->toContain('try { localStorage.setItem(KEY, pref); } catch (err) {}');
});

it('re-applies theme on livewire:navigated so the connection-scope picker preserves dark mode', function (): void {
    // wire:navigate morphs <body> AND replaces <html> attributes from the
    // freshly-fetched response, wiping the runtime-set `dark` class. Without
    // a `livewire:navigated` listener that re-runs `apply(readPref())` the
    // user's chosen theme is lost on every connection switch.
    config()->set('queue-insights.dashboard.theme.enabled', true);

    expect(renderQiLayout())
        ->toContain("window.addEventListener('livewire:navigated'")
        ->toContain('apply(readPref())');
});

it('apply() dispatches qi-theme-applied with preference + resolved scheme', function (): void {
    config()->set('queue-insights.dashboard.theme.enabled', true);

    // Detail carries BOTH preference (light/dark/system) and the resolved
    // scheme (light/dark) — the toggle UI binds to preference, downstream
    // listeners that need to know "is the page actually dark right now?"
    // read resolved without re-querying html.dark.
    $html = renderQiLayout();

    expect($html)
        ->toContain("new CustomEvent('qi-theme-applied'")
        ->toContain('preference: pref')
        ->toContain("resolved: dark ? 'dark' : 'light'");
});

// ── Cloud skin (Laravel-Cloud-inspired light look) ──────────────────────

it('emits the cloud skin marker + CSS when cloud_enabled (default on)', function (): void {
    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain('data-qi-skin="cloud"')
        ->toContain('html[data-qi-skin="cloud"]:not(.dark) body')
        ->toContain('background-attachment: fixed')
        ->toContain('backdrop-filter');
});

it('cloud skin is guarded by :not(.dark) so dark mode is untouched', function (): void {
    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    // Every cloud selector carries the :not(.dark) guard — it can only paint
    // light mode; dark / system-dark fall through to the existing dark theme.
    expect($html)
        ->toContain('html[data-qi-skin="cloud"]:not(.dark) header')
        ->toContain('html[data-qi-skin="cloud"]:not(.dark) main');
});

it('omits the cloud skin entirely when cloud_enabled is false', function (): void {
    config()->set('queue-insights.dashboard.theme.cloud_enabled', false);

    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    expect($html)
        ->not->toContain('data-qi-skin="cloud"')
        ->not->toContain('html[data-qi-skin="cloud"]');
});

it('cloud skin rides light mode independently of the dark-mode toggle flag', function (): void {
    // The cloud skin is a light-mode re-skin; it does not require the
    // light/dark/system toggle to be enabled. With the theme toggle off the
    // dashboard is always-light, and the cloud skin still applies.
    config()->set('queue-insights.dashboard.theme.enabled', false);
    config()->set('queue-insights.dashboard.theme.cloud_enabled', true);

    $html = View::make('queue-insights::layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain('data-qi-skin="cloud"')
        // ...but the dark-mode head script + toggle stay absent (flag off).
        ->not->toContain("var KEY = 'qi-theme'")
        ->not->toContain('aria-label="Theme"');
});
