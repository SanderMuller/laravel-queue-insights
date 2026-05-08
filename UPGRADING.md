# Upgrading

This file lists the migration steps between major / minor versions of `laravel-queue-insights`. Patch releases never require manual steps. The Changelog (`CHANGELOG.md`) is the canonical record of what changed; this file covers only the steps a host must perform to land cleanly on the new version.

## 0.12.x — `aws/aws-sdk-php` moved to `suggest`

`aws/aws-sdk-php` was a hard `require` of this package, even for hosts running purely on Redis, database, or sync queues. It now lives under `suggest`, mirroring `illuminate/queue`'s own approach — the SDK is only loaded at runtime when `SqsSnapshotDriver` is constructed (the `use Aws\Sqs\SqsClient` alias does not trigger autoload on its own).

> ⚠ **Breaking change for hosts on SQS that did NOT explicitly require `aws/aws-sdk-php` in their app's `composer.json`.** Previously the SDK arrived transitively via this package; after upgrading it is no longer pulled in, and the snapshot command will fatal with `Class "Aws\Sqs\SqsClient" not found` the first time a `sqs`-driver connection is snapshotted.

### Action required

Hosts that snapshot at least one SQS connection must add the SDK to their own `composer.json`:

```bash
composer require aws/aws-sdk-php:^3.0
```

Hosts on Redis / database / sync queues only: nothing to do — install footprint drops by ~15 MB.

### How to tell whether you're affected

Check your `config/queue.php` (or env-driven equivalent) for any connection with `'driver' => 'sqs'` that appears in your `config/queue-insights.php` `snapshots` block. If yes, add the explicit `require`. If no, ignore.



The dashboard now ships with a tri-state theme toggle (`light` / `dark` / `system`) and dark-mode styling on every surface. The feature is **default-on** for new installs and existing hosts that have not published the layout view or the config file.

> ⚠ **Visible UX change for operators on system-dark hosts.** The default `system` mode follows `prefers-color-scheme`. Operators on a dark-themed OS will land in dark mode immediately on upgrade. If your team's runbooks, screenshots, or onboarding docs assume the always-light look, refresh them or set `QUEUE_INSIGHTS_DARK_MODE=false` to stay on the old rendering.

### What changes for unpublished installs

If you have NOT run `vendor:publish --tag=queue-insights-views` and have NOT run `vendor:publish --tag=queue-insights-config`:

- The dashboard header gains a sun / monitor / moon segmented pill alongside the polling chip.
- A blocking inline script in `<head>` resolves the user's preference (`localStorage['qi-theme']` → falls back to `prefers-color-scheme` for `system`).
- Body, cards, modals, tabs, panes, and row partials gain `dark:` variants. The `<header>` itself stays Horizon-dark in both modes (intentional brand chrome).
- A `<meta name="color-scheme" content="light dark">` tag is emitted so native form controls and scrollbars adapt.

No manual steps required. The dashboard is gated behind `auth + can:viewQueueInsights`, so only operators see the change.

### Hosts that published the config file (`vendor:publish --tag=queue-insights-config`)

`mergeConfigFrom` is a **shallow** merge — Laravel does not recursively combine nested arrays. Hosts that published `config/queue-insights.php` on an earlier version have a frozen `'dashboard' => [...]` array WITHOUT the new `theme` key. The package default of `enabled => true` will NOT reach those hosts, and the env var (`QUEUE_INSIGHTS_DARK_MODE=false`) cannot turn off something that was never on.

To pick up the toggle, add the `theme` block to your published `config/queue-insights.php`:

```php
'dashboard' => [
    'enabled' => true,
    'path' => 'queue-insights',
    'middleware' => ['web', 'auth', 'can:viewQueueInsights'],
    'polling' => true,
    // Add this block — copy from the package source if you want the
    // env-driven default. Without it, dark mode stays off regardless
    // of QUEUE_INSIGHTS_DARK_MODE.
    'theme' => [
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
    ],
],
```

This is the same shallow-merge gotcha the alerting subsystem documents in CLAUDE.md (`# Alerting — internals + extension points`); the published-config snapshot wins entirely and the package default is silently ignored for the missing key.

### Disabling dark mode

Set the env var to disable the entire feature — the head script, color-scheme meta, and toggle component all skip emission and the dashboard reverts to its pre-feature always-light rendering:

```dotenv
QUEUE_INSIGHTS_DARK_MODE=false
```

Or override via the published config (`config/queue-insights.php`):

```php
'dashboard' => [
    'theme' => [
        'enabled' => false,
    ],
],
```

### Hosts that published the layout view

If you ran `php artisan vendor:publish --tag=queue-insights-views` on an earlier version, your `resources/views/vendor/queue-insights/layouts/app.blade.php` (and any partials you publish) is **pinned to a snapshot** of the old layout. The new feature won't reach those files until you reconcile.

Three reconcile paths, in order of safety:

1. **Re-publish + re-apply your local edits (recommended).**

    ```bash
    rm resources/views/vendor/queue-insights/layouts/app.blade.php
    # ...remove any other view files you previously published
    php artisan vendor:publish --tag=queue-insights-views --force
    ```

    Then re-apply any branding / inline-edits on top of the new file. This is the cleanest option because the new layout adds a new `@php` directive at the top, the FOIT inline script, the `tailwind.config` block, and a header restructure — diffing manually is more work than re-applying a small set of host customisations.

2. **Manual merge against the upstream diff.**

    Compare your published file against the corresponding tag in this repository (`git diff vX.Y.Z..vX.(Y+1).0 -- resources/views/layouts/app.blade.php`) and replay the hunks. The changes are mechanical: surface tokens gain `dark:` variants, the body picks up `dark:bg-gray-950 dark:text-gray-100`, and the header gains an inline `theme-toggle` component next to the polling chip. The `tailwind.config = { darkMode: 'class' }` block is required even if you decide to keep the toggle disabled — without it, `dark:` variants on dashboard surfaces can leak through Tailwind's default `media`-mode behaviour on system-dark hosts.

3. **Keep your published version as-is.**

    The kill-switch flag (`dashboard.theme.enabled`) gates emission only inside the package's own layout. A host running their own published copy needs to merge the feature in manually, OR delete the published copy entirely and fall back to the package default. Either is fine; "do nothing" leaves you on the old look indefinitely.

### Files modified by this feature

For hosts taking path #2:

- `resources/views/layouts/app.blade.php` — head script, color-scheme meta, tailwind.config, body classes, inline-style overrides for copy-button and qi-time tooltip, JSON-colorizer dual-class spans, theme-toggle render in the header.
- `resources/views/components/theme-toggle.blade.php` — new file (segmented sun / monitor / moon).
- `resources/views/dashboard.blade.php` — connection-scope dropdown dark variants.
- `resources/views/components/{details,failed,batch,pending}-modal.blade.php` — modal panel + payload internals dark variants.
- `resources/views/components/{nested-data,structured-payload,serialized-properties,stack-trace,job-classes-section,throughput-sparkline,flash-banner,copy-button,hint,meta-pill,list-row,qi-time}.blade.php` — payload sub-components, atoms.
- `resources/views/partials/*.blade.php` — every row partial, banner, filter form, pagination, persistent-hero, tabs-workspace, tab-button, etc.
- `resources/views/livewire/alert-rules-panel*.blade.php` — alert-rules-panel surfaces.
- `config/queue-insights.php` — new `dashboard.theme.enabled` flag.

The full per-element token map is documented in `internal/specs/dashboard-dark-mode.md` (§3.1 / §3.2). A regression-guard test in `tests/Feature/View/DarkModeRegressionGuardTest.php` scans every blade view file and fails when a light surface token appears without a paired `dark:` companion — useful as a CI gate if you fork the package.
