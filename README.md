# Laravel Queue Insights

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![License](https://img.shields.io/github/license/sandermuller/laravel-queue-insights.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/laravel-queue-insights?style=flat)](https://packagist.org/packages/sandermuller/laravel-queue-insights)

Self-hosted queue observability for Laravel. A Horizon-style dashboard that doesn't lock you into the Redis queue driver.

## Features

- Live depth, in-flight, and delayed counts per queue. Works on SQS, Redis, and database queues.
- Pending & delayed-job inspector per queue — individual queued jobs with class FQCN and `runs in <countdown>` for delayed jobs. Driver-agnostic (event-captured into Redis), so SQS gets the same view as Redis and database queues.
- Wait time per queue (p50 / p95) and per job. Measures enqueue to worker pickup.
- 24h throughput sparkline (processed + failed) with hover tooltips per hour, alongside a headline-stats panel: jobs/min, jobs past hour, failed past hour, max throughput hour, max wait p95, max runtime p95.
- Queues grouped into *Needs attention* (errored or stale) and *Healthy* so a broken queue can't hide in a long list.
- Per-job-class metrics: 24h processed and failed, average and max duration, last run.
- Recent completed jobs. Metadata-only by default; opt-in payload capture with a pluggable sanitizer. Filter row mirrors the failed-jobs filter (connection, queue, class, from, to).
- Recent failed jobs from Laravel's `failed_jobs` table, with a filter row over connection, queue, class, and date range. Filters persist in the URL.
- Retry failed jobs from the dashboard, single or bulk. Gated, rate-limited, and audit-logged.
- Markdown export of failed-job details for handing off to an AI agent or pasting into a tracker.
- Standalone Livewire + Blade. No Filament or Nova coupling.
- Small Redis footprint, bounded and auto-evicting. No external observability service required.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- Redis (for insights storage)
- `livewire/livewire` 3 or 4 (only if you use the bundled dashboard route).

CI runs against three Livewire resolver legs: Livewire 3.0, Livewire 3 latest, and Livewire 4 latest. Coverage is PHP-side only. The JS and Alpine paths aren't browser-tested, so do a smoke render in your own staging before upgrading the host.

## Install

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config
```

The service provider auto-discovers.

## Payload capture

Off by default. Laravel payloads embed serialized and sometimes encrypted job state, and a regex over JSON keys can't sanitize that safely.

Three modes via `QUEUE_INSIGHTS_CAPTURE_PAYLOADS`:

| Mode              | Behavior                                                                                                                                 |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| `off` *(default)* | No payload persisted.                                                                                                                    |
| `metadata`        | `displayName`, `maxTries`, `timeout`, `backoff` only. No user data, no serialized command body.                                          |
| `full`            | Raw body after a sanitizer pass. Apps with sensitive jobs MUST bind a custom `PayloadSanitizer` that understands their job shape.        |

Read [`SECURITY.md`](SECURITY.md) before enabling `full`.

## Dashboard

Mounts at `/queue-insights` when `dashboard.enabled=true` and `livewire/livewire` is installed. Define the `viewQueueInsights` Gate in your app:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('viewQueueInsights', fn ($user) => $user->isAdmin());
```

### Retry permissions (write actions)

Retrying a failed job is a write action and needs its own Gate, separate from the read-only `viewQueueInsights`:

```php
Gate::define('retryFailedJobs', fn ($user) => $user->isAdmin());
```

Without that Gate, the Retry button stays hidden in the failed-job modal, the bulk Retry button stays hidden above the failed-jobs table, and direct calls to the underlying Livewire methods (`retryFailed`, `retryFailedBulk`) return 403.

The retry path uses Laravel's first-party `queue:retry` Artisan command, so it's idempotent against an already-retried row and works regardless of queue driver.

Guards on the retry path:

- 30 retries per minute, per user.
- The server rejects a bulk retry when the matching set is over 100 rows. The UI shows a "narrow to retry" hint instead of the action button.
- The server also rejects a bulk retry when no filter is set, so you can't accidentally one-click retry every failed job.
- Every retry writes an `info`-level log line with channel `queue-insights.retry`, including the user id and the active filter set. Forward that to your audit log.

### Retry workflow

To triage a failed job:

1. Open the dashboard and find the row in the **Recent failed** list.
2. Optional: click **Filter ⌄** above the list and narrow by connection, queue, class, or date range. The URL updates as you change a field, so the filtered view is shareable.
3. Click any row to open the failed-job modal. You'll see the exception, stack trace, payload, and metadata.
4. To retry one job, click *Retry* in the modal header. The button flips to a red "Confirm retry?" for two seconds; click again to fire. The modal closes and a green banner confirms dispatch. If `queue:retry` exits non-zero, you get a red banner instead of a misleading success.
5. To retry several at once, set at least one filter. A *Retry N jobs* button appears next to the section heading, with the same two-click confirm pattern. Anything matching more than 100 rows shows a *N matches · narrow to retry* hint instead of an action button.

A failed retry never leaves the dashboard in a half-broken state. The row is either re-dispatched (and removed from `failed_jobs`) or left alone.

### Filtering

Both *Recent completed* and *Recent failed* have a collapsible filter row above the list. Click **Filter ⌄** to expand. Each field binds to a short query-string key, so a narrowed view is shareable and bookmarkable.

Connection, Queue, and Class are populated as `<select>` dropdowns from the configured snapshots and the 24h class roster — no free-text typos.

#### Recent failed filter

| Field      | Query-string key | Match semantics                                                      |
|------------|------------------|----------------------------------------------------------------------|
| Connection | `fc`             | Exact (`connection` column)                                          |
| Queue      | `fq`             | Exact (`queue` column)                                               |
| Class      | `fk`             | Anchored prefix substring on `payload.displayName`, case-insensitive |
| From       | `ffrom`          | `failed_at >= <Y-m-d> 00:00:00`                                      |
| To         | `fto`            | `failed_at <= <Y-m-d> 23:59:59`                                      |

The class filter avoids JSON-extract syntax, which diverges across MySQL, Postgres, and SQLite. Instead it runs `LOWER(payload) LIKE '%"displayname":"<input>%'`, which produces the same match set on all three. Picking `App\Jobs\SendEmail` matches that exact class, and the underlying `LIKE` semantics still anchor the prefix so e.g. selecting a parent namespace would match its descendants.

The filter row also drives the bulk-retry scope. The *Retry N jobs* button retries the same set the list is showing.

#### Recent completed filter

Same five fields, separate state, separate query-string keys. Class is pre-filtered at the storage layer (per-class Redis stream key); the other four narrow the already-fetched 50-row default cap in PHP.

| Field      | Query-string key | Match semantics                                          |
|------------|------------------|----------------------------------------------------------|
| Connection | `cc`             | Case-insensitive substring                               |
| Queue      | `cqu`            | Case-insensitive substring                               |
| Class      | `ck`             | Exact FQCN — picks a single per-class stream             |
| From       | `cfrom`          | `processed_at >= <Y-m-d> 00:00:00`                       |
| To         | `cto`            | `processed_at <= <Y-m-d> 23:59:59`                       |

### Wait time

Wait time is the gap between enqueue and worker pickup. Duration is the gap between worker pickup and completion. They're different numbers, and wait time is the one to look at when depth / in-flight look fine but jobs feel slow.

It shows up in two places:

- Queue rows show a `p50 / p95` Wait column, computed over the most recent 1000 jobs on that queue and refreshed every poll. Shows `—` until 10 samples have accumulated.
- The completed-job and failed-job modals show `wait <human> (NN ms)` next to the Duration row. Shows `—` for jobs queued before the `JobQueued` listener was wired, and for drivers that don't stamp `payload.uuid`.

Capture is automatic. Installing the package wires an `Illuminate\Queue\Events\JobQueued` listener that records the enqueue timestamp, so no host-app config is needed. The cost per job is one Redis `SETEX` at push, plus a `GET` + `ZADD` + `ZREMRANGEBYRANK` + `EXPIRE` chain at worker pickup. Retention: 1h on the per-uuid `pushed:` key, 7d on the per-uuid `wait:` sample, rolling 1000 most-recent on the per-queue ZSET.

A 7-day clock-skew guard rejects any wait sample over that, so a producer host with bad NTP can't poison the percentile pool indefinitely.

### Pending & delayed jobs

Each queue row in the dashboard has a collapsible inspector that shows individual pending and delayed jobs — class FQCN, queued-at humanized, and (for delayed) `runs in <countdown>`. The toggle button shows the tracked count next to the queue's badges; click to expand. The expand state is URL-shareable (`?qopen=connection:queue`).

The data is **event-captured into Redis**, not peeked from the queue driver. The `JobQueued` listener stamps a per-uuid hash + per-queue sorted set into the package's Redis namespace; `JobProcessing` / `JobProcessed` / `JobFailed` clean up. Driver-agnostic by design — works for SQS, where there's no way to peek individual messages without consuming them, alongside Redis and database queues.

Bounded storage:

- ~500 bytes per pending job (uuid + class FQCN + connection + queue + queued_at + available_at).
- Per-queue cap (`pending.max_per_queue`, default 10000) enforced via `ZREMRANGEBYRANK` — when the cap is hit, the lowest-score (earliest `available_at`) entry is dropped first.
- TTL safety net (`pending.ttl_seconds`, default 86400 = 24h) drops orphans whose cleanup listener never fired (worker crash, raw `Queue::push()` outside Laravel's event flow).

The dashboard compares the tracked count against the snapshot's `depth + delayed` — when they diverge by more than `pending.gap_warn_threshold` (default 5), a `+N gap` badge appears on the toggle and a banner inside the inspector body warns that the lists are a sample, not a complete enumeration. Read the queue counters above for totals when the gap is non-zero. Gap usually points to one of:

- A worker crashed mid-pickup and the `JobProcessing` listener didn't fire (TTL eventually cleans).
- Jobs are being pushed via raw `Queue::push()` outside Laravel's standard dispatch (no `JobQueued` event raised).
- The `pending.max_per_queue` cap kicked in on a high-volume queue (more jobs in the queue than the tracked sample).

To opt out (memory-bounded production), set `QUEUE_INSIGHTS_PENDING_ENABLED=false`. The listener writes become no-ops, the inspector toggle disappears, and existing keys age out via TTL.

### Customising row markup

The dashboard's queue, completed, and failed lists are each rendered through a Blade partial, plus a shared filter-form partial. They're publishable — a host that wants to swap a row's columns or restyle the filter chrome can publish the partials and edit them in place without forking the whole `dashboard.blade.php` view:

```bash
php artisan vendor:publish --tag=queue-insights-views
```

| Partial                                 | What it renders                                                |
|-----------------------------------------|----------------------------------------------------------------|
| `partials/queue-row.blade.php`          | One row in the Queues list (Needs attention + Healthy groups)  |
| `partials/completed-row.blade.php`      | One row in Recent completed                                    |
| `partials/failed-list-row.blade.php`    | One row in Recent failed                                       |
| `partials/filter-form.blade.php`        | The collapsible 5-field filter form (used by both completed + failed) |
| `partials/stat-tile.blade.php`          | One tile in the headline-stats panel beside the throughput sparkline  |

If you only want to override one row layout, leave the others unpublished — Blade will fall back to the package's bundled version for those.

### Embedding the dashboard inside an admin layout

Disable the bundled route and mount the Livewire component yourself:

```php
// config/queue-insights.php
'dashboard' => ['enabled' => false, /* ... */],
```

```blade
{{-- resources/views/admin/queue-insights.blade.php --}}
@extends('admin.layout')

@section('content')
    @livewire('queue-insights-dashboard')
@endsection
```

### Custom payload sanitizer

The default `KeyRedactingSanitizer` can't see inside PHP-serialized `data.command` bodies. Apps with sensitive jobs should bind their own:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;

$this->app->bind(PayloadSanitizer::class, YourSanitizer::class);
```

## Ops runbook

### Dashboard signals

| Signal | Meaning |
|---|---|
| `—` on in-flight / delayed | Driver can't produce the metric (Null / sync), or the live cache expired (>90s since the last successful snapshot). |
| `stale` badge | No snapshot ran in the last 2 minutes. |
| `error` badge | Last snapshot run failed for this queue. Hover for the error message (10-minute TTL). |
| `no snapshot yet` | The command has never completed successfully against this queue. |

### Driver-specific quirks

- SQS values are AWS approximations. `GetQueueUrl` is cached for 1h in Redis; the first run per new queue name costs one extra API call.
- Redis reads `LLEN queues:{name}` plus `ZCARD` on `:reserved` and `:delayed`. Matches Laravel's own queue key convention.
- Database depth includes rows whose reservation has expired (crashed workers leave their jobs poppable again). Matches `DatabaseQueue::getNextAvailableJob()` exactly.

### Key-prefix strategies

- Shared Redis (multi-tenant, or multiple apps or envs on the same Redis): keep the default `QUEUE_INSIGHTS_KEY_PREFIX=qm:{APP_ENV}:`. Safe against collision.
- Dedicated Redis: override to `QUEUE_INSIGHTS_KEY_PREFIX=qm:` to drop the env segment and shorten every key.

### Alerting

Enable via `QUEUE_INSIGHTS_ALERTS_ENABLED=true` and declare thresholds in `config/queue-insights.php`:

```php
'alerts' => [
    'enabled' => true,
    'cooldown_seconds' => 900,
    'thresholds' => [
        ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000],
    ],
],
```

Listen for `SanderMuller\QueueInsights\Events\QueueDepthExceeded` and route notifications via `Notification::route(...)` (Slack, Teams, email, PagerDuty, etc.).

## License

MIT. See [`LICENSE`](LICENSE).
