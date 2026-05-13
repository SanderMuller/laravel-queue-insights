# Dashboard dark mode — internals + edit points

AI-facing reference for the dark-mode subsystem. End-user docs live in
`README.md` (the `### Dark mode` section); the design rationale +
phase-by-phase deviations live in `internal/specs/dashboard-dark-mode.md`.
Read this **before** touching any of the files listed below.

## What it does

Tri-state theme toggle (`light` / `dark` / `system`) in the dashboard
header. The header itself stays Horizon-dark in both modes by design;
the rest of the chrome (main bg, hero, tabs, panes, modals, partials)
gains `dark:` Tailwind variants that fire when `<html class="dark">`
is set. Default-on for new installs (post-audit Phase 6 flip). Hosts
disable via `QUEUE_INSIGHTS_DARK_MODE=false`.

## Architecture — single owner, dispatch / listen

```
<head> (survives wire:navigate; runs once per full document load)
   └── FOIT inline script
         ├── reads localStorage['qi-theme'] → falls back to 'system'
         ├── writes documentElement.dataset.theme  (preference)
         ├── writes documentElement.classList.toggle('dark')  (effective)
         ├── matchMedia('(prefers-color-scheme: dark)').addEventListener
         │     └── re-applies when in 'system' mode and OS pref changes
         ├── window.addEventListener('qi-theme-change')
         │     └── localStorage write + apply()
         └── apply() → window.dispatchEvent('qi-theme-applied',
                         { detail: { preference, resolved } })

<body> (morphed by wire:navigate)
   └── theme-toggle component (re-instantiated each morph)
         ├── x-data reads documentElement.dataset.theme on init
         ├── x-on:qi-theme-applied.window → mirrors $event.detail.preference
         ├── role="radiogroup" + per-button role="radio" + aria-checked
         ├── roving tabindex (only checked radio in Tab order)
         ├── arrow-key handlers (Left/Right/Up/Down) cycle via moveBy(±1)
         └── click → window.dispatchEvent('qi-theme-change', { detail })
```

The head script is the **single owner** of `localStorage`, `matchMedia`,
`documentElement.dataset.theme`, and `documentElement.classList`. The
toggle never writes any of those directly — it dispatches a custom event
and lets the head script handle the side effects. This makes
`wire:navigate` lifecycle clean: the toggle's Alpine instance can be
torn down and re-instantiated freely; the head script's listeners
survive at document level and never duplicate.

## Token mapping (from §3.1 / §3.2 of the spec)

The dark-mode audit used a fixed token map — any new view file should
follow the same pairings or update the spec.

| Light | Dark |
|---|---|
| `bg-gray-50` (body) | `dark:bg-gray-950` |
| `bg-white` (cards, modals) | `dark:bg-gray-900` |
| `bg-gray-100` (subtle fill) | `dark:bg-gray-800` |
| `bg-gray-950/5` (tonal fill) | `dark:bg-white/10` |
| `text-gray-900` | `dark:text-gray-100` |
| `text-gray-700` | `dark:text-gray-300` |
| `text-gray-500` (content muted) | `dark:text-gray-300` (bumped for AA) |
| `text-gray-400` (decorative) | `dark:text-gray-400` (held) |
| `text-gray-300` (very muted) | `dark:text-gray-500` |
| `border-gray-950/5` | `dark:border-white/10` |
| `ring-gray-950/5` / `ring-gray-950/10` | `dark:ring-white/10` |
| `divide-gray-950/5` | `dark:divide-white/10` |
| `text-X-700` (semantic) | `dark:text-X-300` |
| `text-X-900` (bold semantic) | `dark:text-X-200` |
| `bg-X-50` (semantic tint) | `dark:bg-X-900/40` |
| `bg-X-100` (semantic chip) | `dark:bg-X-900/60` |
| `ring-X-600/20` (semantic ring) | `dark:ring-X-400/30` |
| `border-X-200/60` (sub-table rule) | `dark:border-X-400/20` |
| `divide-X-200/60` (sub-table divider) | `dark:divide-X-400/20` |

**Always-dark surfaces** (no `dark:` variants applied):
- `<header class="bg-gray-900">` in `layouts/app.blade.php` — brand chrome.
- `qi-time` tooltip — uses `gray-700` in dark, `gray-900` in light (CSS rule).
- The throughput-sparkline tooltip.

## Touchpoints — files that own this subsystem

| File | Role |
|---|---|
| `config/queue-insights.php` | `dashboard.theme.enabled` env-backed flag. |
| `src/Support/ConfigValidator.php::validateDashboard` | Type-check the flag at boot. |
| `resources/views/layouts/app.blade.php` | FOIT head script, color-scheme meta, `tailwind.config` block, body dark classes, inline-style html.dark overrides, JSON-colorizer dual-class spans, inline theme-toggle render. |
| `resources/views/components/theme-toggle.blade.php` | Tri-state segmented pill, `role="radiogroup"` + roving tabindex + arrow-key cycling, dispatches `qi-theme-change`, listens for `qi-theme-applied`. |
| `tests/Feature/View/DarkModeRegressionGuardTest.php` | File-level pair-check guard. Scans every blade in `resources/views/` (extracting only class-attribute contexts to skip comments/JS strings) and fails when a light surface token has no paired dark companion. |

## Behavioural rules — DO NOT VIOLATE

1. **The head script is the single owner of `matchMedia` and
   `localStorage`.** The toggle never subscribes directly. Two
   listeners would leak under `wire:navigate` because the toggle's
   Alpine instance is destroyed/re-instantiated per morph but the
   head script's listener lives at document level. One owner, one
   subscription.
2. **`tailwind.config = { darkMode: 'class' }` is unconditional.**
   Even when the master flag is off. Without it, Tailwind v3
   defaults to `media` mode, which means `dark:` variants would
   auto-fire on `prefers-color-scheme: dark` regardless of the
   flag — exposing half-themed surfaces to system-dark hosts during
   any future incremental audit. Layout's gating is on the head
   script + meta + body classes only.
3. **`html.dark`-scoped CSS is unconditional.** The two CSS rules
   for `[data-qi-copy][data-qi-copied]` (copy-button feedback) and
   `#qi-time-tooltip` (tooltip surface) are emitted always. They
   only match when `.dark` is present on `<html>`, so emitting
   them with the flag off is free.
4. **Custom event names are kebab-case (`qi-theme-change` /
   `qi-theme-applied`).** Alpine's `x-on:` directive parses
   colon-in-event-name unreliably across versions. Renaming was
   the cleanest fix.
5. **The toggle is rendered inline in the layout's header, NOT
   pushed via `@push('header-scope')`.** Layout-side `@push`
   directives fire BEFORE `{{ $slot }}` renders the dashboard's
   own pushes — the toggle would land to the LEFT of the
   connection-scope picker (wrong order). Inline placement skips
   the stack mechanism and lets the toggle sit alongside the
   polling chip via a shared `ml-auto` flex wrapper.
6. **Default flag is `env(QUEUE_INSIGHTS_DARK_MODE, true)`.**
   Hosts upgrading get the toggle automatically. The audit phases
   shipped surfaces incrementally with the flag off; flipping it
   is the "ship-it" switch. Opt-out path is the env var, not a
   code change.
7. **State separation: preference vs effective.**
   `documentElement.dataset.theme` holds the preference
   (`light` / `dark` / `system`); `documentElement.classList`
   holds the resolved theme (`dark` class present / absent). In
   `system` mode the two intentionally diverge. `qi-theme-applied`
   detail carries `{ preference, resolved }` so listeners can
   bind to either without re-querying `html.dark`.

## What NOT to do

- Don't add `prefers-contrast` or forced-colors handling. Out of
  scope; revisit if a host requests it.
- Don't expose a runtime config-mutation surface (admin UI to
  change theme palettes). Out of scope. Hosts who want different
  shades publish the layout view and patch it.
- Don't switch to `darkMode: 'media'`. The class-based strategy
  is what makes the kill-switch flag work and the toggle's
  forced overrides function. `media` mode would make
  `'dark'`/`'light'` selections impossible.
- Don't extend the regression-guard test to per-element
  granularity without adding a parser (currently a regex pass
  over class-attribute extracts). File-level catches the gross
  "added a new card without dark variants" regression; per-element
  drift is what visual review is for.
- Don't rename `qi-theme-change` / `qi-theme-applied` without
  updating the head script, the toggle component, and the
  Phase 1 / Phase 6 string-grep tests in lockstep.

## Config surface

```php
'dashboard' => [
    // ...
    'theme' => [
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
    ],
],
```

When `enabled = false`: the FOIT head script + `<meta name="color-scheme">`
+ body dark classes + theme-toggle component all skip emission. Tailwind
config (with `darkMode: 'class'` + safelist), `html.dark`-scoped CSS, and
`dark:` variants on individual surfaces ship anyway — they're inert because
no `.dark` class ever lands on `<html>`.
