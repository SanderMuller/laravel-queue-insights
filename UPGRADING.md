# Upgrade Guide

Migration steps between minor/major versions of `laravel-queue-insights`. Patch releases never require manual steps. `CHANGELOG.md` is the canonical record of what changed; this file covers only host-side migration.

Newest at the top. Across-version jumps must complete intermediate sections in order.

## Upgrading from 0.15 to 0.16

### Pending-zset key now uses the connection's configured default queue

`RecordJobQueued` previously wrote `pending-zset:{conn}:default` when `$event->queue` was empty, while the worker side cleared `pending-zset:{conn}:{configured-default}`. On connections whose default isn't literally `default` (Vapor / SQS with `SQS_QUEUE=staging_default`, any Redis / database connection with a non-`default` `queue` config) the keys diverged and `oldest_pending` tripped on long-completed jobs.

**Action.** If `queue-insights.snapshots` lists `'queue' => 'default'` but the connection's real default differs, update the entry:

```php
// config/queue-insights.php
'snapshots' => [
    ['connection' => 'sqs', 'queue' => 'staging_default'],
],
```

Hosts whose configured default is already `'default'` need no action.

**Cutover window.** Pre-0.16 entries on `pending-zset:{conn}:default` age out via `pending.ttl_seconds` (24 h). Until they do, `oldest_pending` may keep firing if `snapshots` still references the old literal `default`. Either update `snapshots` (recommended) or drop the orphans once after upgrade:

```bash
redis-cli DEL '{prefix}pending-zset:sqs:default'
```

### `ScheduleReader::recentRuns()` no longer hydrates `output` / `exception`

The list path returns `null` for the per-row `output` and `exception` slots. The drilldown modal hydrates both via `ScheduleReader::runDetail()` / `runOutput()`.

**Action.** Hosts consuming `RunsQuery` / `recentRuns()` from their own code and reading `$row['output']` / `$row['exception']` must switch to `runDetail($taskKey, $runId)`.

## Upgrading from 0.14 to 0.15

### Failed-pane class filter URL key removed (`?fk=` → `?ck=`)

The Failed list now shares `?ck=` with the Completed list. The old `?fk=` key is silently dropped on hydration.

**Action.** Rewrite bookmarked / pinned dashboard URLs:

```diff
- ?fk=App\Jobs\SendEmail
+ ?ck=App\Jobs\SendEmail
```

Match semantics unchanged (anchored prefix substring on `payload.displayName`, case-insensitive).

## Upgrading from 0.13 to 0.14

### Scheduler alerts route through `QueueAlertNotification`

`Events\ScheduledTaskFailed` / `Missed` / `Hung` still fire — listeners against them keep working. Notification dispatch is new and gated.

**Gate.** Notifications fire only when **both** `alerts.enabled` and `scheduler.alerts.enabled` are true:

```bash
QUEUE_INSIGHTS_ALERTS_ENABLED=true
QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED=true
```

**Cooldown key namespace migration.** Renamed for parity with queue-side alerts:

| Before | After |
|---|---|
| `{prefix}sched:alert:cooldown:failed:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_failed:task:{taskKey}` |
| `{prefix}sched:alert:cooldown:hung:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_hung:task:{taskKey}` |
| `{prefix}sched:alert:cooldown:missed:{taskKey}` | `{prefix}alert:cooldown:scheduled_task_missed:task:{taskKey}` |

The first sweep tick after upgrade may fire one duplicate alert per `(task, rule)` actively cooling down at the boundary. Drop the obsolete keys once verified:

```bash
redis-cli --scan --pattern '{prefix}sched:alert:cooldown:*' | xargs -r redis-cli DEL
```

**Per-domain channel override.** New `scheduler.alerts.channels` block routes scheduler alerts to a different Slack channel / mail recipient / log channel. Omit to fall back to `alerts.channels`. See the README's "Per-domain channel routing" section.

## Upgrading from 0.11 to 0.12

### `aws/aws-sdk-php` moved from `require` to `suggest`

The SDK is now only loaded when `SqsSnapshotDriver` is constructed. Hosts that snapshot at least one SQS connection must require it explicitly:

```bash
composer require aws/aws-sdk-php:^3.0
```

Without it, the snapshot command fatals with `Class "Aws\Sqs\SqsClient" not found` the first time an `sqs`-driver connection is snapshotted.

Redis / database / sync-only hosts: no action; install footprint drops ~15 MB.

## Upgrading from 0.10 to 0.11

### Dashboard dark mode (default-on)

The dashboard ships a tri-state theme toggle (`light` / `dark` / `system`) and dark-mode styling on every surface. `system` follows `prefers-color-scheme`, so operators on a dark-themed OS land in dark mode immediately on upgrade.

**Opt out.**

```dotenv
QUEUE_INSIGHTS_DARK_MODE=false
```

### Hosts that published the config file

`mergeConfigFrom` is a shallow merge — a published `config/queue-insights.php` from before 0.11 has a frozen `dashboard` array without the new `theme` key, and the env var cannot turn off something that was never on. Add the block manually:

```php
'dashboard' => [
    // ...
    'theme' => [
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
    ],
],
```

### Hosts that published the layout view

Re-publish and re-apply local edits:

```bash
rm resources/views/vendor/queue-insights/layouts/app.blade.php
php artisan vendor:publish --tag=queue-insights-views --force
```

> `--force` overwrites local edits. Diff first if your published layout carries branding or other customisations.

The new layout adds a head FOIT script, a `tailwind.config = { darkMode: 'class' }` block, body dark classes, an inline `theme-toggle` component in the header, and `dark:` variants across surfaces. The `darkMode: 'class'` directive is required even if you keep the toggle disabled — without it, `dark:` variants leak through Tailwind's default `media`-mode behaviour on system-dark hosts.
