# Changelog

All notable changes to `laravel-queue-insights` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## 0.1.0 - 2026-04-26

First public release of `sandermuller/laravel-queue-insights` — self-hosted, driver-agnostic queue observability for Laravel. Horizon-style dashboard without the Redis-queue lock-in.

### Highlights

#### Live queue gauges (driver-agnostic)

- Per-queue **depth / in-flight / delayed** sampled across SQS, Redis, and database queues.
- 24h history per metric, surfaced in queue cards.
- Stale-snapshot indicator + per-queue snapshot-error badge.

#### Per-job-class metrics

- Last 24h **processed / failed / avg + p95 + max duration / last run**.
- Click-through filter on the Recent completed table.

#### Throughput sparkline

- 24h hourly rollup of processed + failed counts across all classes.
- Per-hour Alpine-driven hover tooltip (no 500ms native-`<title>` lag), colour-banded for the failed series.

#### Recent completed + Recent failed lists

- Stream-backed completed list with metadata-only by default; opt-in payload capture (`metadata` or `full`) with a pluggable `PayloadSanitizer`.
- Failed list reads Laravel's standard `failed_jobs` table.
- Both tables: full-row click opens the details modal, keyboard-accessible (Enter / Space), focus-visible outlines.

#### Structured details modal

- Identity hero (class FQCN + connection + queue), metrics row (duration / attempts / processed-at), grouped raw-fields panel.
- **JSON tab** with syntax highlighter for the sanitized payload body, **Raw fields** tab for KV display.
- Decoded `data.command` properties via a safe `unserialize(allowed_classes: false)` reader — recursive Blade renderer with click-to-expand for nested objects.
- Capture-mode badge surfaces the active retention level so operators don't misread "no payload" as "no job".
- Parsed stack-trace component (vendor frames toggleable) on the failed-job modal.
- **Copy buttons** with click feedback (bg flash + check icon swap) for stream id / UUID / stack trace / a Markdown export of the full failed-job context (intended hand-off to AI agents and trackers — uses dynamic fence length so embedded triple-backticks don't break the export).

#### Wait-time capture (new)

- Records enqueue → worker pickup latency via a `JobQueued` listener that decodes `payload.uuid` and stamps a Redis `pushed:{uuid}` key, then computes `wait_ms` on `JobProcessing`.
- Per-queue **p50 / p95** surfaces in queue cards (rolling 1000 most-recent samples; renders `—` until 10 samples accumulate).
- Per-job **Wait** line in completed + failed modals next to Duration.
- 7-day clock-skew guard rejects implausible samples — a producer host with bad NTP can't poison the percentile pool indefinitely.

#### Failed-jobs filter (new)

- Live Livewire filter row (collapsed by default) over connection / queue / class FQCN / date range.
- URL-persistent via Livewire `#[Url]` attribute — share / bookmark a narrowed view.
- Class filter is anchored prefix substring on `payload.displayName`, wrapped in `LOWER(...)` to produce the same match set across MySQL / Postgres / SQLite.
- Filtered view drives the bulk-retry scope (see below).

#### Retry failed jobs (new)

- **Single retry** button in the failed-job modal, **bulk retry** button next to the Recent failed table when filters are active.
- Goes through Laravel's first-party `queue:retry` Artisan command — works across all queue drivers, idempotent against already-retried rows.
- Two-click in-button confirm pattern (no modal-on-modal).
- Server-enforced safety contract:
  - Distinct `retryFailedJobs` Gate (read-only `viewQueueInsights` Gate is intentionally separate).
  - `RateLimiter` 30 retries / minute / user.
  - Bulk action **hard-rejects** when filters are empty or the matching set exceeds 100 rows — no silent truncation.
  - Non-zero `queue:retry` exit codes surface as red banners (dead-letter / driver-rejected rows are visible, not silently reported as success).
  - Audit log entry per retry with sanitized filter context (control bytes neutralised, length-capped at 80 chars).
  

#### Embeddable + Livewire 3 or 4

- Standalone Livewire + Blade dashboard. No Filament / Nova coupling.
- Mounts at `/queue-insights` (gated by `viewQueueInsights`) or embeds inside a host admin layout via `<livewire:queue-insights-dashboard>`.
- Composer constraint `^3.0 || ^4.0` — Pulse-style dual support so hosts on either Livewire major can install.

### Compatibility

- **PHP 8.3+**
- **Laravel 11 or 12** (host `illuminate/*` constraints)
- **Redis** for insights storage (Predis or phpredis client both supported and exercised in CI)
- **livewire/livewire 3 or 4** *(only when using the bundled dashboard route — capture and snapshot run without it)*

### Security notes

- Payload capture is **off by default**. Enabling `full` mode requires a deliberate config + a custom sanitizer when jobs carry sensitive data — see `SECURITY.md`.
- Default sanitizer (`KeyRedactingSanitizer`) redacts `password`, `token`, `secret`, `api_*key`, `authorization` at any depth and truncates oversized scalar fields, while preserving PHP-serialized blobs intact (the modal needs the full blob to extract decoded properties).
- Retry write surface requires a separate `retryFailedJobs` Gate; without it the dashboard is fully read-only.

### Install

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config

```
Service provider auto-discovers. See [README](https://github.com/SanderMuller/laravel-queue-insights/blob/main/README.md) for queue snapshot config, gate setup, and the embedding pattern.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/commits/0.1.0
