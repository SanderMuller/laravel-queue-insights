# Laravel Queue Insights

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![License](https://img.shields.io/github/license/sandermuller/laravel-queue-insights.svg?style=flat-square)](LICENSE)

Self-hosted, driver-agnostic queue observability for Laravel. Horizon-style dashboard without the Redis-queue lock-in.

## Features

- Live **depth / in-flight / delayed** per queue, driver-agnostic (SQS, Redis, database).
- **Wait time** per queue (p50/p95) and per job — enqueue → worker pickup latency.
- 24h throughput sparkline (processed + failed) with per-hour tooltips.
- Per-job-class metrics: processed / failed / avg + max duration / last run.
- Recent completed jobs (metadata-only by default; opt-in payload capture with pluggable sanitizer).
- Recent failed jobs (reads Laravel's `failed_jobs` table) with **filter row** for connection / queue / class / date range, URL-persistent.
- **Retry failed jobs** in-app — single or bulk, gated, rate-limited, audit-logged.
- **Markdown export** of failed-job details for quick hand-off to AI agents / trackers.
- Standalone Livewire + Blade dashboard. No Filament / Nova coupling.
- Minimal Redis footprint, bounded & auto-evicting. Zero external observability cost.

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- Redis (for insights storage)
- `livewire/livewire` 3 or 4 *(only if using the bundled dashboard route. CI exercises three resolver legs: Livewire 3.0 / Livewire 3-latest / Livewire 4-latest. PHP-side only — JS/Alpine interactions are not browser-tested, so ship a smoke render in your own staging before upgrading hosts.)*

## Install

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config
```

Auto-discovery registers the service provider.

## Payload capture

Off by default. Laravel payloads embed serialized/encrypted job state that regex-on-JSON-keys cannot sanitize.

Three modes via `QUEUE_INSIGHTS_CAPTURE_PAYLOADS`:

| Mode              | Behavior                                                                                                                                 |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| `off` *(default)* | No payload persisted.                                                                                                                    |
| `metadata`        | `displayName`, `maxTries`, `timeout`, `backoff` only — no user data, no serialized command body.                                         |
| `full`            | Raw body after sanitizer pass. **Host apps with sensitive jobs MUST bind a custom `PayloadSanitizer`** that understands their job shape. |

See [`SECURITY.md`](SECURITY.md) for the threat model before enabling `full`.

## Dashboard

Mounts at `/queue-insights` when `dashboard.enabled=true` and `livewire/livewire` is installed. Host app defines the `viewQueueInsights` Gate:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('viewQueueInsights', fn ($user) => $user->isAdmin());
```

### Retry permissions (write actions)

The dashboard can re-dispatch failed jobs (single + bulk) directly from the
modal. This is a **write** action and requires its own Gate, distinct from
the read-only `viewQueueInsights` Gate above:

```php
Gate::define('retryFailedJobs', fn ($user) => $user->isAdmin());
```

Behaviour without the gate:

- The Retry button stays hidden in the failed-job modal.
- The bulk Retry button stays hidden above the failed-jobs table.
- Direct calls to the underlying Livewire methods (`retryFailed`,
  `retryFailedBulk`) return 403.

Operational guards in the retry path:

- **Rate limit:** 30 retries / minute / user.
- **Bulk cap:** server hard-rejects bulk retry when the matching set
  exceeds 100 rows; the UI shows a "narrow to retry" hint.
- **Filter requirement:** server hard-rejects bulk retry when no filter
  is set (footgun guard against one-click "retry every failed job").
- **Audit trail:** each retry logs at `info` level with channel
  `queue-insights.retry`, including the user id and the active filter
  set. Forward to your audit log.

The retry path goes through Laravel's first-party `queue:retry` Artisan
command, so it's idempotent against an already-retried row and works
across all queue drivers.

### Retry workflow

Operator flow for triaging a failed job:

1. Open the dashboard → **Recent failed** table.
2. *(Optional)* Click **Filter ⌄** above the table and narrow by
   connection / queue / class FQCN / date range. The URL updates as you
   type so the filtered view is shareable.
3. Click any row to open the failed-job modal — exception, stack trace,
   payload, metadata.
4. **Single retry:** click *Retry* in the modal header. The button flips
   to a red "Confirm retry?" for 2 seconds; click again to fire. The
   modal closes; a green banner confirms dispatch. A non-zero
   `queue:retry` exit code surfaces as a red banner instead of silent
   success.
5. **Bulk retry:** with at least one filter active, a *Retry N jobs*
   button appears next to the table heading. Same two-click confirm
   pattern. The server hard-rejects requests that match more than 100
   rows — the UI swaps the button for a *N matches · narrow to retry*
   hint instead.

A failed retry never leaves the dashboard in a broken state — the row
is either re-dispatched (and removed from `failed_jobs`) or untouched.

### Failed-jobs filter

The filter row is hidden by default; click **Filter ⌄** above the
*Recent failed* table to expand it. Filters bind to the URL via short
query-string keys so a narrowed view is shareable / bookmarkable:

| Field      | Query-string key | Match semantics                                                              |
|------------|------------------|------------------------------------------------------------------------------|
| Connection | `fc`             | Exact (`connection` column)                                                  |
| Queue      | `fq`             | Exact (`queue` column)                                                       |
| Class      | `fk`             | **Anchored prefix substring** on `payload.displayName`, case-insensitive     |
| From       | `ffrom`          | `failed_at >= <Y-m-d> 00:00:00`                                              |
| To         | `fto`            | `failed_at <= <Y-m-d> 23:59:59`                                              |

The class filter avoids JSON-extract syntax (which diverges across
MySQL / Postgres / SQLite) — instead it does a `LOWER(payload) LIKE
'%"displayname":"<input>%'` which matches the same set everywhere. A
search for `App\Jobs\Send` therefore matches `App\Jobs\SendEmail` and
`App\Jobs\SendReceipt`, but won't false-match a substring inside an
unrelated argument value.

The filter row also drives the bulk-retry scope — the *Retry N jobs*
button retries the same set the table is showing.

### Wait time

Wait time = enqueue → worker pickup latency. Different from
**duration** (worker pickup → completion). Useful for spotting queue
backlogs that the depth / in-flight gauges don't yet reflect.

Two surfaces:

- **Per queue** (queue cards): `wait p50 / p95` micro-stats line. Computed
  over the most recent 1000 jobs on that queue, refreshed every poll.
  Renders `—` until at least 10 samples have accumulated.
- **Per job** (failed-job + completed-job modals): `wait <human> (NN ms)`
  next to the Duration row. Renders `—` for legacy jobs (queued before
  the `JobQueued` listener was wired) or for drivers that don't stamp
  `payload.uuid`.

Capture is automatic — adding the package wires the
`Illuminate\Queue\Events\JobQueued` listener that records the enqueue
timestamp. No host-app config needed. Cost: one extra Redis `SETEX` per
push, one extra `GET` + `ZADD` + `ZREMRANGEBYRANK` + `EXPIRE` per worker
pickup. Bounded retention: 1h on the per-uuid `pushed:` key, 7d on the
per-uuid `wait:` sample, rolling 1000 most-recent on the per-queue ZSET.

A 7-day clock-skew guard rejects samples larger than that — a producer
host with bad NTP can't poison the percentile pool indefinitely.

### Embedding the dashboard inside an admin layout

Disable the bundled route and mount the Livewire component yourself wherever you want it:

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
| `—` on in-flight / delayed | Driver can't produce the metric (Null / sync), or live cache expired (>90s since last successful snapshot). |
| `stale` badge | No snapshot ran in the last 2 minutes. |
| `error` badge | Last snapshot run failed for this queue — hover for the error message (TTL 10 min). |
| `no snapshot yet` | Command never completed successfully against this queue. |

### Driver-specific quirks

- **SQS** — values are AWS approximations. `GetQueueUrl` is cached 1h in Redis; first run per new queue name costs one extra API call.
- **Redis** — reads `LLEN queues:{name}` + `ZCARD` on `:reserved` / `:delayed`. Matches Laravel's own queue key convention.
- **Database** — depth includes rows whose reservation has expired (crashed workers become poppable again). Matches `DatabaseQueue::getNextAvailableJob()` exactly.

### Key-prefix strategies

- **Shared Redis** (multi-tenant, same Redis for multiple apps or envs): leave default `QUEUE_INSIGHTS_KEY_PREFIX=qm:{APP_ENV}:`. Safe on collision.
- **Dedicated Redis**: override to `QUEUE_INSIGHTS_KEY_PREFIX=qm:` to drop the env segment and shorten every key.

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

Listen to `SanderMuller\QueueInsights\Events\QueueDepthExceeded` and route notifications via `Notification::route(...)` (Slack, Teams, email, PagerDuty, etc).

## License

MIT. See [`LICENSE`](LICENSE).
