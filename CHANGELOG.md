# Changelog

All notable changes to `laravel-queue-insights` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## 0.3.0 - 2026-04-26

### Highlights

#### Pending & delayed jobs

Each queue row in the dashboard gets a collapsible inspector. The toggle next to the queue's badges shows a tracked-count chip; click it to open. Inside: two compact-list mini-tables, **Pending** (`available_at <= now`) and **Delayed** (`available_at > now`), each row showing the job's class FQCN and a humanized timestamp (`queued 4s ago` / `runs in 2m 14s`).

The expand state is URL-shareable (`?qopen=connection:queue`) — paste a dashboard URL to a peer and they land on your expanded inspector view.

#### Driver-agnostic — including SQS

The data is **event-captured into Redis**, not peeked from the underlying queue driver. The `JobQueued` listener stamps a per-uuid hash + per-queue sorted set on every queued job. `JobProcessing` clears on the pending → in-flight transition; `JobProcessed` and `JobFailed` do belt-and-suspenders cleanup for the rare case the processing listener was missed.

Native driver-peek would have worked for Redis (`LRANGE`) and database (`SELECT FROM jobs`), but SQS doesn't expose individual queued messages without consuming them. Capturing into our Redis namespace gives the same view across all three.

All four listeners route the queue value through `CanonicalQueueKey` so an SQS producer (which sees a queue URL) and the matching worker (which reports just the queue name) write to and clean from the same zset key.

#### Bounded storage

- **Per-queue cap** (`pending.max_per_queue`, default 10000) — `ZREMRANGEBYRANK` evicts by score (lowest `available_at` first) when capped. Per-queue zset stays at exactly the cap.
- **TTL safety net** (`pending.ttl_seconds`, default 24h) — clears orphans whose cleanup listener never fired (worker crash, raw `Queue::push()` outside Laravel's standard event flow).
- **Storage cost** — ~500 bytes per pending job (uuid + class FQCN + connection + queue + queued_at + available_at). 10K cap = ~5MB Redis per queue worst case. Bounded.

#### Tracking-gap drift signal

Our zset is event-derived; `Driver::depth() + Driver::delayed()` is the queue-of-truth. When they diverge by more than `pending.gap_warn_threshold` (default 5), a `+N gap` badge appears on the toggle and a banner inside the inspector body reads:

> **Tracking gap.** N jobs on the queue are not in our pending tracking — the lists below are a sample, not a complete enumeration. Trust the queue counters (above) for totals.

Operators always have a truth signal — the snapshot count up top is authoritative; the lists below are a *sample* when the gap is non-zero. Common gap causes:

- Worker crash mid-pickup, `JobProcessing` listener didn't fire (TTL eventually cleans).
- Jobs pushed via raw `Queue::push()` outside Laravel's dispatch path (no `JobQueued` event raised).
- High-volume queue exceeding `max_per_queue` (more in the queue than the tracked sample).

#### Opt-out

Set `QUEUE_INSIGHTS_PENDING_ENABLED=false`. All four listener writes become no-ops, the inspector toggle disappears, residual data ages out via TTL.

### Public API surface (additive)

- `QueueInsights::pendingJobs(string $connection, string $queue, int $limit = 50): array`
- `QueueInsights::delayedJobs(string $connection, string $queue, int $limit = 50): array`
- `QueueInsights::pendingTrackedCount(string $connection, string $queue): int`
- `Support\PendingJobsReader` — new helper class (mirrors the existing `Support\WaitTimeMetrics` pattern)
- `Support\ConfigValidator::validatePending(array $pending): void`
- `QueueInsightsDashboard::$expandedQueueKey` — new `#[Url(as: 'qopen')]` prop
- `QueueInsightsDashboard::toggleQueueInspector(string $key): void` — Livewire action
- New config block:

```php
'pending' => [
    'enabled' => env('QUEUE_INSIGHTS_PENDING_ENABLED', true),
    'max_per_queue' => 10000,
    'ttl_seconds' => 86400,
    'gap_warn_threshold' => 5,
],

```
**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.2.1...0.3.0

## 0.2.1 - 2026-04-26

### Highlights

#### Laravel 13 support

Composer constraints widened so the package installs cleanly into Laravel 13 host applications:

- `illuminate/console`, `illuminate/contracts`, `illuminate/queue`, `illuminate/redis`, `illuminate/support` now accept `^13.0` alongside the existing `^11.0` and `^12.0`.
- `orchestra/testbench` (dev) accepts `^11.0`.
- `pestphp/pest`, `pestphp/pest-plugin-arch`, `pestphp/pest-plugin-laravel` (dev) accept `^4.0` — Pest plugin Laravel v4.1.0 is the first version with `laravel/framework: ^13.0` in its constraints, and v4.x in turn requires Pest v4.x.

CI matrix gains a `13.*` row paired with `testbench: '11.*'`, exercised under both `prefer-lowest` and `prefer-stable` × PHP 8.3 + 8.4 × predis + phpredis. Laravel 11 and 12 legs continue to run.

#### Documents publishable view partials (carry-over from 0.2.0)

The README now documents the row partials added in 0.2.0 — `partials/queue-row`, `partials/completed-row`, `partials/failed-list-row`, `partials/filter-form`, `partials/stat-tile` — and shows that hosts can publish them individually to override row markup without forking the whole `dashboard.blade.php` view.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.2.0...0.2.1

## 0.2.0 - 2026-04-26

### Highlights

#### Dashboard redesign

- White-on-white card stacks replaced with **compact divider lists** for Queues, Recent completed, and Recent failed. Rows are denser, scan faster, and use a shared `partials/*-row.blade.php` so the three lists keep identical spacing and column geometry.
- **Body palette** flips to `bg-gray-50` so the white panels read as defined cards instead of floating on white-on-white. No section bars — each section is prefixed by a monochrome Heroicon (`gray-400`) plus a `text-lg` heading. Visual identity stays calm, scan stays fast.
- **Column headers** anchor each list. Recent completed now has `Job · Queue · Runtime · Completed`; Recent failed has `Job · Queue · Failed`; Queues has `Queue · Depth · In-flight · Delayed · Wait · Status`. Fixes the floaty-numbers feel where a runtime value had no obvious label nearby.
- **Chevron** on Recent completed rows so the click affordance matches Recent failed (operator no longer wonders whether the row is interactive).

#### Headline stats panel

Six Horizon-inspired stats sit beside the throughput sparkline as a `lg:col-span-2` + `1` grid — same total height as the sparkline alone, no completed/failed pushdown:

| Metric             | Source                                                |
| ------------------ | ----------------------------------------------------- |
| Jobs / min         | `latest_hour.processed / 60`                          |
| Jobs past hour     | `latest_hour.processed`                               |
| Failed past hour   | `latest_hour.failed` (red when > 0)                   |
| Max throughput     | `max(throughput[*].processed)` over 24h               |
| Max wait p95       | `max(queues[*].wait_p95_ms)` (amber when > 5s)        |
| Max runtime p95    | `max(classes[*].p95_ms)` from the 24h class roster    |

All values derived from data already loaded for the dashboard render — zero new Redis round-trips.

#### Recent completed filter

Recent completed picks up the same filter row pattern Recent failed has. Five fields, URL-persistent state, narrows the 50-row default cap.

| Field      | Query-string key | Match semantics                              |
| ---------- | ---------------- | -------------------------------------------- |
| Connection | `cc`             | Case-insensitive substring                   |
| Queue      | `cqu`            | Case-insensitive substring                   |
| Class      | `ck`             | Exact FQCN — picks a per-class Redis stream  |
| From       | `cfrom`          | `processed_at >= <Y-m-d> 00:00:00`           |
| To         | `cto`            | `processed_at <= <Y-m-d> 23:59:59`           |

Class is pre-filtered at the storage layer (already-existing per-class `completed:{FQCN}` stream key); the other four narrow the fetched rows in PHP via a new `Support\CompletedRowFilter` value object.

The Job classes section is dropped — the same per-class metric data still feeds the Class dropdown, so operators can still scope by class without scrolling to a separate panel.

#### Filter dropdowns instead of free-text

Connection, Queue, and Class are `<select>` dropdowns now in both Recent completed and Recent failed. Options come from the configured snapshots and the 24h class roster — no typo-prone free-text entry. Date inputs stay as native `<input type="date">`. The shared `partials/filter-form.blade.php` is included by both sections so they can never drift again.

#### At-risk queues group

Queues are split into two ringed panels:

- **Needs attention** — queues with `error` or `stale` status. Red-ringed panel above the rest, sub-heading shows the count. A broken queue can't hide on page 2 of a long list.
- **Healthy** — everything else. Same column header geometry, neutral gray ring.

Rows still tint individually (red for error, amber for stale) so single-row state is visible after a glance, but the panel-level grouping makes the at-a-glance "is anything wrong?" answer one heading away.

#### Workbench preview for contributors

Adds a Testbench `Workbench/` scaffold so the dashboard can be previewed locally without wiring it up in a host application. `vendor/bin/testbench serve` (or pointing Herd at the package directory) boots a Livewire-mounted seeded dashboard at `/` with 6 example queues mixing healthy / backlog / stale / errored, 24h throughput, 5 completed rows, 4 failed rows, and 4 classes.

- `public/index.php` — Herd entry that defines `TESTBENCH_WORKING_PATH` and delegates to `vendor/orchestra/testbench-core/laravel/bootstrap`.
- `workbench/app/Http/Livewire/PreviewDashboard.php` — fresh Livewire component that renders `queue-insights::dashboard` with hardcoded seeded data; action methods are no-op stubs so modal opens / filter clears don't error during preview.
- `workbench/app/Providers/WorkbenchServiceProvider.php` — registers the preview component and a `/` route.
- `testbench.yaml` is now tracked (was previously gitignored), so contributors get the wired provider + array cache/session env out of the box.

### Public API surface

- New `#[Url]`-bound props on `QueueInsightsDashboard`: `completedFilterConnection` (`cc`), `completedFilterQueue` (`cqu`), `completedFilterFrom` (`cfrom`), `completedFilterTo` (`cto`). Existing `selectedClass` picks up `#[Url(as: 'ck')]` so it shares to the query string like its peers.
- New public method `QueueInsightsDashboard::clearCompletedFilters()`.
- New `Support\CompletedRowFilter` value object — immutable, mirrors the shape of the existing `Support\FailedJobFilters`. Exposes `apply(array $rows): array` and `isEmpty(): bool`.
- New view partials under `resources/views/partials/` — `queue-row`, `completed-row`, `failed-list-row`, `filter-form`, `stat-tile`. Publishable by hosts that want to override row markup without forking the whole dashboard view.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.1.0...0.2.0

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
