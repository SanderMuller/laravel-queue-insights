# Upgrade Guide

Migration steps between minor/major versions of `laravel-queue-insights`. Patch releases never require manual steps. `CHANGELOG.md` is the canonical record of what changed; this file covers only host-side migration.

Newest at the top. Across-version jumps must complete intermediate sections in order.

## Upgrading from 0.31 to 0.32

### Suffixed queues key on their logical name

**BREAKING for Laravel Cloud hosts and any SQS connection with `SQS_SUFFIX` set.**
No action needed on Redis, database, or unsuffixed SQS connections.

A connection carrying a queue-name suffix gives one queue two names: the
**logical** `stats` you dispatch to, and the **physical** `stats-{suffix}` AWS
knows, which is what the queue URL ends with. Queue Insights used to key
whichever one reached a given code path — the producer saw the logical name on
`JobQueued`, the worker read the physical one off the job. Two keys for one
queue, which is why pending entries never cleared and a queue rendered twice on
the dashboard.

Everything now keys the logical name. Two things change on the host side:

1. **Dashboard rows collapse.** A queue that showed as `stats` (queued counts
   only) plus `stats-abc123` (everything else) becomes a single `stats` row.
2. **Prometheus `queue` label values change** from `stats-abc123` to `stats`.
   Grafana panels, recording rules, and alert rules pinned to the old label
   value need updating, or they go silent.

Keys written under the physical name are not migrated. They age out on their
own TTLs (`pending.ttl_seconds`, default 24h, plus the metric TTLs), or clear
immediately:

```bash
php artisan queue-insights:purge-pending cloud stats-abc123 --force
```

Pass the **physical** name there — the command targets keys as they are
stored, which is the point of an orphan scrubber.

### Laravel Cloud needs no `driver_overrides` entry

If you silenced the `unknown queue driver` warning with an override, remove it —
it now wins over the built-in `cloud` support and would keep snapshots dead
silently:

```php
// config/queue-insights.php — delete this
'driver_overrides' => ['cloud' => 'null'],
```

Then list the queues to snapshot; Cloud does not add itself to `snapshots[]`.
`config('queue.connections.cloud.queues')` holds every managed queue on the
environment:

```php
'snapshots' => [
    ['connection' => 'cloud', 'queue' => 'default'],
    ['connection' => 'cloud', 'queue' => 'stats'],
],
```

## Upgrading from 0.30 to 0.31

### The scheduler roster now rebuilds on console commands, not on every boot

**BREAKING for hosts driving the scheduler through a custom command.**

Queue Insights keeps a snapshot of your scheduled-task roster (`qi:sched:tasks`
and `qi:sched:tasks:order`). It used to rewrite that snapshot from
`Schedule::events()` on `app->booted` — every artisan invocation and every web
request, each paying a Redis round-trip and logging a warning when Redis was
unreachable.

It now rebuilds only when a scheduler-relevant console command starts, matched
against the new `scheduler.snapshot_rebuild_commands` list:

```php
// config/queue-insights.php
'scheduler' => [
    'snapshot_rebuild_commands' => ['schedule:*', 'queue-insights:*'],
],
```

Console-only is deliberate: `withSchedule()` and `routes/console.php` tasks do
not exist during a web request, so a web-side rebuild would persist a partial
roster.

**Who is affected.** Hosts that run the scheduler through their own wrapper
command instead of `schedule:run` — say `cron:tick` or `ops:scheduler`. On a
published config predating this release the key is absent, the defaults apply,
and the wrapper matches neither pattern, so the roster silently stops
refreshing. The dashboard panel keeps showing the last snapshot it captured.

**What to do.** Add the wrapper to the list. Exact names match literally, a
trailing `*` matches by prefix:

```php
'snapshot_rebuild_commands' => ['schedule:*', 'queue-insights:*', 'cron:tick'],
```

Keep the list narrow — every listed command pays a Redis round-trip at startup.

**No action needed** if you run `schedule:run` or `schedule:work` (the Laravel
default), or if you had already set `scheduler.snapshot_rebuild = false` because
you seed the roster yourself.

A host that has never run the scheduler now sees an empty panel until
`schedule:run` (or `queue-insights:schedule:list`) fires once, where before a
single web request was enough to populate it.

## Upgrading from 0.19 to 0.20

### `horizon.autodiscover` is now runtime-gated

**BREAKING:** `queue-insights.horizon.autodiscover` is now tri-state, and the
default value `true` has a new meaning.

| Value | Old behaviour | New behaviour |
|---|---|---|
| `false` | Never autodiscover | Unchanged |
| `true` (default) | Autodiscover whenever `laravel/horizon` is **installed** | Autodiscover only when Horizon's **service provider is loaded** in the running app |
| `'force'` | — (new) | Autodiscover from `config/horizon.php` regardless of provider state |

**Who is affected.** Only apps where Horizon is *installed but its provider is
not registered* — e.g. Vapor/SQS setups using `composer.json`
`extra.laravel.dont-discover` + conditional `$app->register(HorizonServiceProvider::class)`.
There, config-walk autodiscovery now correctly stops surfacing Horizon
supervisor queues that never receive a snapshot. Apps running Horizon normally
(provider auto-discovered) see **no change**.

**This is not cosmetic.** Autodiscovered connections feed more than the Queues
panel — when one stops being discovered it also leaves the dashboard
connection nav + per-connection scoping/auth, the
`allPendingJobs` / `allDelayedJobs` / `allInFlightJobs` aggregation, the
`connection_drift` detector, the snapshot command's pruning set, and the
Prometheus per-connection collectors. That's the intended cleanup when Horizon
isn't the runtime, but if you *were* relying on those rows (rare — a
non-Horizon app monitoring a Horizon environment), opt back in:

```dotenv
QUEUE_INSIGHTS_HORIZON_AUTODISCOVER=force
```

**Action — none required for most hosts.** If you run Horizon as your queue
runtime, the provider is loaded and `true` behaves as before. Set `'force'`
only if you deliberately want config-derived rows without Horizon's provider
registered.

## Upgrading from 0.18 to 0.19

### Redis Cluster support

Queue Insights now runs against cluster-mode Redis. Previously its multi-key Lua scripts and pipelines failed with `CROSSSLOT` on a cluster, because the keys spanned hash slots — depth snapshots, completed/failed counters, and the pending → in-flight transition all silently dropped their writes.

**Action — required only if `redis_connection` points at a Redis Cluster.** Two changes:

1. Enable hash-tag pinning so every package key hashes to a single slot:

```dotenv
QUEUE_INSIGHTS_REDIS_CLUSTER=true
```

2. Make sure the matching Redis connection in `config/database.php` is a real cluster connection — a plain connection pointed at a cluster endpoint will not follow `MOVED` redirects:

```php
// config/database.php
'redis' => [
    'client' => 'phpredis',

    'options' => [
        'cluster' => 'redis', // use the Redis Cluster protocol
    ],

    'clusters' => [
        'queue-insights' => [
            [
                'host' => env('REDIS_QUEUE_INSIGHTS_HOST', '127.0.0.1'),
                'port' => env('REDIS_QUEUE_INSIGHTS_PORT', 6379),
            ],
        ],
    ],
],
```

Then point `QUEUE_INSIGHTS_REDIS` at that connection name (`queue-insights` above). Redis Cluster does not support multiple databases — drop any `database` key from the connection; the hash-tagged `key_prefix` is what isolates the keyspace.

**Action — none required for standalone (non-cluster) Redis.** Leave `QUEUE_INSIGHTS_REDIS_CLUSTER` unset/false; nothing changes.

Trade-off: hash-tag pinning co-locates the entire Queue Insights keyspace on a single cluster slot — one node. Intentional and fine for the package's bounded keyspace, but it does not shard. See the Redis Cluster section in the README.

## Upgrading from 0.16 to 0.17

### Horizon supervisor queues now auto-discovered

When `laravel/horizon` is installed, the dashboard Queues panel + pending/in-flight aggregation now union every supervisor's `{connection, queue}` from `horizon.environments` with the static `snapshots[]` list. Resolution mirrors Horizon's own `ProvisioningPlan` (Str::is glob on env keys, `array_replace_recursive` with `horizon.defaults`).

**Action — none required for most hosts.** Static `snapshots[]` entries still win on collision. If you want the previous static-only behaviour:

```dotenv
QUEUE_INSIGHTS_HORIZON_AUTODISCOVER=false
```

If you run multiple Horizon environments off the same `APP_ENV`:

```dotenv
QUEUE_INSIGHTS_HORIZON_ENV=production
```

### `horizon.silenced` entries now silenced in Queue Insights

Classes silenced via Horizon — operator-edited `config/horizon.php` or upstream packages writing to it at boot (spatie/laravel-health writes `Spatie\Health\Jobs\HealthQueueJob` when `silence_health_queue_job` is on) — are now suppressed across our dashboard Silenced tab, failure-rate detectors, notifications, and the failed-jobs SQL exclusion.

**Action — none required.** Strictly additive. Operator-edited `queue-insights.silenced` entries still take precedence on display casing.

### `connection_aliases` for cross-connection drift

When a job is dispatched on Laravel queue connection `A` but processed by a worker bound to connection `B` (both pointing at the same physical Redis DB — common with Horizon supervisor setups), Queue Insights previously wrote `pending-zset:A:{queue}` and tried to clear `pending-zset:B:{queue}`. Keys never met; pending rows were orphaned until TTL.

**Action — required if you have drift.** Publish the alias map in `config/queue-insights.php`:

```php
'connection_aliases' => [
    'redis' => 'redis-staging',
    'redis-staging' => 'redis-staging',
],
```

Validator rules: identity allowed; transitive chains (`A => B, B => C`) rejected; mutual cycles rejected. Flatten chains manually.

### Prometheus label churn when `connection_aliases` is published

`connection` label values on both queue-scoped + class-scoped metrics switch to the canonical alias on rollout. Affected series:

- `queue_insights_depth{connection,queue}`
- `queue_insights_inflight{connection,queue}`
- `queue_insights_delayed{connection,queue}`
- `queue_insights_wait_p50{connection,queue}`, `_p95{connection,queue}`
- `queue_insights_jobs_processed_total{class,connection}`
- `queue_insights_jobs_failed_total{class,connection}`
- `queue_insights_job_duration_*{class,connection}`

Operators relying on the pre-aliased name in alert rules / Grafana panels need a Prometheus `relabel_configs` rule:

```yaml
- source_labels: [connection]
  regex: redis
  target_label: connection
  replacement: redis-staging
```

### Composer `require-dev`

`laravel/horizon: ^5.29` was added to `require-dev` so the autodiscovery test suite autoloads `Laravel\Horizon\Horizon::class`. Hosts that already have Horizon installed are unaffected; CI matrix gains Horizon.

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
# Shipped helper (since 0.16.1) — dry-runs by default; --force to delete.
# Refuses unconfigured connections; only DELs per-uuid hashes whose stored
# queue field matches the target (bystander pending entries are never touched).
php artisan queue-insights:purge-pending sqs default              # dry-run, prints what it would touch
php artisan queue-insights:purge-pending sqs default --force      # actually purge

# Or raw redis-cli (older versions / one-off):
redis-cli DEL '{prefix}pending-zset:sqs:default'
```

### `ScheduleReader::recentRuns()` list path now omits `output` / `exception` / `skip_reason` / `is_background`

The list path returns `null` (or `false` for `is_background`) for these four per-row slots — none are rendered by the run-row blade and `output` / `exception` can each grow to several KiB. The drilldown modal hydrates the full row via `ScheduleReader::runDetail()` / `runOutput()`.

**Action.** Hosts consuming `RunsQuery` / `recentRuns()` from their own code and reading any of `$row['output']`, `$row['exception']`, `$row['skip_reason']`, `$row['is_background']` must switch to `runDetail($taskKey, $runId)`.

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
