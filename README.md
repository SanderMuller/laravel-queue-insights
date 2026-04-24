# Laravel Queue Insights

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![License](https://img.shields.io/github/license/sandermuller/laravel-queue-insights.svg?style=flat-square)](LICENSE)

Self-hosted, driver-agnostic queue observability for Laravel. Horizon-style dashboard without the Redis-queue lock-in.

## Features

- Live **depth / in-flight / delayed** per queue, driver-agnostic (SQS, Redis, database).
- 24h history chart per metric.
- Per-job-class metrics: processed / failed / avg + max duration / last run.
- Recent completed jobs (metadata-only by default; opt-in payload capture with pluggable sanitizer).
- Recent failed jobs (reads Laravel's `failed_jobs` table).
- Standalone Livewire + Blade dashboard. No Filament / Nova coupling.
- Minimal Redis footprint, bounded & auto-evicting. Zero external observability cost.

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- Redis (for insights storage)
- `livewire/livewire` 3+ *(only if using the bundled dashboard route)*

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
