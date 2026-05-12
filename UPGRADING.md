# Upgrading

This file lists the migration steps between major / minor versions of `laravel-queue-insights`. Patch releases never require manual steps. The Changelog (`CHANGELOG.md`) is the canonical record of what changed; this file covers only the steps a host must perform to land cleanly on the new version.

## Pending-zset key alignment for hosts whose default queue isn't literally `default` (0.16)

The producer-side listener (`RecordJobQueued`) previously fell back to the literal string `'default'` when `$event->queue` was empty — the JobQueued event shape Laravel emits when a job is dispatched without an explicit `->onQueue()`. The worker side reads the real queue off the popped job (`$job->getQueue()`), so on hosts where the connection's configured default isn't the literal `'default'` (Vapor / SQS with `SQS_QUEUE=staging_default` is the canonical case, but the same applies to any redis / database connection whose `queue` config differs) the producer wrote `pending-zset:{conn}:default` while the worker tried to clean `pending-zset:{conn}:{configured-default}`. Keys diverged, pending entries never cleared, `oldest_pending` tripped on long-completed jobs.

0.16 resolves the connection's configured default queue (`queue.connections.{connection}.queue`) at both producer and worker sites via the new `CanonicalQueueKey::fromOrDefault()` helper, so both sides land on the same key.

### Action required

**If your `queue-insights.snapshots` config lists `'queue' => 'default'` but your actual queue connection routes to a different default (e.g. `staging_default`)**, update the snapshots entry to match the real queue. Otherwise the dashboard / Prometheus exporter / alert detectors keep reading the (now-empty) `pending-zset:{conn}:default` while real traffic is keyed under `:{conn}:{real-default}` — surfaces will silently show zero pending for that queue.

```php
// config/queue-insights.php — before
'snapshots' => [
    ['connection' => 'sqs', 'queue' => 'default'],
],

// after — match the connection's real default
'snapshots' => [
    ['connection' => 'sqs', 'queue' => 'staging_default'],
],
```

### Cutover window

Hosts running 0.15 with the bug will have pre-existing entries on `pending-zset:{conn}:default` at upgrade time. Those entries:

- Are no longer touched by the post-upgrade listeners (producer + worker now key the configured-default zset instead).
- Age out via the `pending.ttl_seconds` TTL (24 h default).

During that 24 h window, if your `snapshots` config still references the old literal `default` queue, `oldest_pending` may keep firing on the stale entries. The two mitigations:

1. **Recommended**: update `snapshots[].queue` (above) so the detector reads the new zset, and let the orphans age out naturally on the old key.
2. **One-off cleanup**: redis-cli the pre-fix orphans yourself once after upgrade:

   ```bash
   # Replace {prefix} with your `queue-insights.key_prefix` (default 'qi:').
   redis-cli DEL '{prefix}pending-zset:sqs:default'
   ```

   Only do this if you've confirmed no actively-pending jobs are still keyed under the old shape (i.e. no producers running on 0.15 against the same Redis).

Hosts whose configured default queue is already the literal string `'default'` (the common single-queue case) need no action — both old and new code paths key the same zset for them.

## Failed-pane class filter URL key removed (`?fk=` → `?ck=`)

The Failed list's class filter is now bound to the same Livewire prop the Completed list uses (`selectedClass`), so a class picked in either dropdown — or via a click on the Classes tab — scopes both panes simultaneously. As part of that unification, the Failed-pane's old `?fk=` URL key was removed; the surviving key is `?ck=` (which both panes now share).

### Action required

Bookmarks or saved dashboard URLs that pin a Failed-list class via `?fk=App\Jobs\SendEmail` need to migrate to `?ck=App\Jobs\SendEmail`. There's no auto-redirect; the old key is silently dropped on hydration.

If you've shared dashboard URLs in runbooks, Slack pins, or PagerDuty annotations, search for `?fk=` and `&fk=` and rewrite. The match semantics are unchanged (anchored prefix substring on `payload.displayName`, case-insensitive).

## Scheduler alerts route through `QueueAlertNotification`

Scheduler `Failed` / `Missed` / `Hung` detections now route through the same notification pipeline as queue-side alerts. Hosts that wired listeners against `Events\ScheduledTaskFailed` / `Missed` / `Hung` keep working — the typed events still fire alongside the new notification path.

### Gate semantics

Notifications fire only when **both** `alerts.enabled` AND `scheduler.alerts.enabled` are true. The package-wide `alerts.enabled` is the master kill switch for all log/slack/mail emission across both queue and scheduler domains. Hosts that previously ran with `alerts.enabled=false` and `scheduler.alerts.enabled=true` for the typed events alone keep that exact behaviour — the typed events continue firing, no notifications go out unless the master switch is also flipped on.

To opt into scheduler notifications, set both:

```bash
QUEUE_INSIGHTS_ALERTS_ENABLED=true
QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED=true
```

### Cooldown key namespace migration

The on-disk cooldown key shape changed for parity with queue-side alerts:

| Before | After |
|---|---|
| `{prefix}sched:alert:cooldown:failed:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_failed:task:{taskKey}` |
| `{prefix}sched:alert:cooldown:hung:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_hung:task:{taskKey}` |
| `{prefix}sched:alert:cooldown:missed:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_missed:task:{taskKey}` |

A SETNX on the new namespace doesn't see the old keys, so the **first sweep tick after upgrade may fire one duplicate alert per (task, rule) that was actively cooling down at the upgrade boundary**. Acceptable one-off cost; subsequent ticks honour the new namespace.

Drop the obsolete keys once the migration is verified (replace `qi:` with the configured `key_prefix` if different):

```bash
redis-cli --scan --pattern 'qi:sched:alert:cooldown:*' | xargs -r redis-cli DEL
```

### Optional per-domain channel override

A new `scheduler.alerts.channels` config block lets operators route scheduler alerts to a different Slack channel / mail recipient list / log channel. Omit the block (or keep all channels disabled) and scheduler issues fall back to `alerts.channels` — single-list installs Just Work without any config change. See README's "Per-domain channel routing" subsection.

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
