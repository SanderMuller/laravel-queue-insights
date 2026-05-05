# Laravel Queue Insights

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-queue-insights/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-queue-insights/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-queue-insights.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-queue-insights)
[![License](https://img.shields.io/github/license/sandermuller/laravel-queue-insights.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/laravel-queue-insights?style=flat)](https://packagist.org/packages/sandermuller/laravel-queue-insights)

Self-hosted queue observability for Laravel. A Horizon-style dashboard that doesn't lock you into the Redis queue driver.

## Live demo

[**queue-insights-demo-main-wgcmqf.laravel.cloud**](https://queue-insights-demo-main-wgcmqf.laravel.cloud) — public preview hosted on Laravel Cloud, seeded with realistic fixtures (eight queues, alerts firing, batched + chained jobs, recent completed/failed lists). The deploy uses the same `workbench/app/Support/PreviewSeeder.php` that powers `vendor/bin/testbench serve` locally, so what you see is the package rendering against real Redis reads — not a static screenshot.

## Features

- Live depth, in-flight, and delayed counts per queue. Works on SQS, Redis, and database queues.
- Pending & delayed-job inspector per queue — individual queued jobs with class FQCN and `runs in <countdown>` for delayed jobs. Driver-agnostic (event-captured into Redis), so SQS gets the same view as Redis and database queues.
- Batched-jobs section — per-batch progress bar, processed/failed/pending counts, finished/cancelled state, and an expandable per-item rollup that links each uuid back to the existing completed/failed modal. Per-row chip on completed/failed/pending lists jumps to the batch in one click.
- Chained-jobs visibility — completed and failed rows surface the next job in a `Bus::chain([...])` chain via a small `↳ Next (+N)` chip and a Chain section in the modal, sourced directly from the job's serialized payload. Backward `↰ From {parent}` lineage is captured opportunistically via short-lived Redis claim tickets, so the modal and failed-row markdown export show which job ran *before* this one too.
- Wait time per queue (p50 / p95) and per job. Measures enqueue to worker pickup.
- 24h throughput sparkline (processed + failed) with hover tooltips per hour, alongside a headline-stats panel: jobs/min, jobs past hour, failed past hour, max throughput hour, max wait p95, max runtime p95.
- Queues grouped into *Needs attention* (errored or stale) and *Healthy* so a broken queue can't hide in a long list.
- Per-job-class metrics: 24h processed and failed, average and max duration, last run.
- Recent completed jobs. Metadata-only by default; opt-in payload capture with a pluggable sanitizer. Filter row mirrors the failed-jobs filter (connection, queue, class, from, to).
- Recent failed jobs from Laravel's `failed_jobs` table, with a filter row over connection, queue, class, and date range. Filters persist in the URL.
- Retry failed jobs from the dashboard, single or bulk. Gated, rate-limited, and audit-logged.
- Markdown export of failed-job details for handing off to an AI agent or pasting into a tracker.
- Alerting — eight built-in detectors (depth, stalled, oldest-pending, stuck-inflight, failure-rate, slow-p95, snapshot-errored, backlog-growing) with per-rule cooldown and built-in `log` / `slack` / `mail` channels via the standard Laravel notification stack. Typed events fire regardless of channel config so hosts can hook custom routing.
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

### Multi-connection scoping

When you monitor more than one queue connection (e.g. a multi-tenant app with one connection per tenant, or a mixed `sqs` + `redis` setup), the dashboard exposes connection as a **first-class navigation axis**, not a filter dropdown:

- `/queue-insights` — un-scoped, every monitored connection aggregated into one view.
- `/queue-insights/{connection}` — scoped to a single connection. Every panel narrows: queue rows, alerts strip, snapshot watchdog, pending/delayed/in-flight inspectors, batches, recent completed/failed lists, headline stats (jobs / min, throughput sparkline, p95 wait, max runtime), per-class metrics, and the alert-rules panel's depth thresholds.

A tab strip above the headline cards renders one tab per allowed connection plus an "All" tab. The strip auto-suppresses when only one connection is monitored.

The `{connection}` segment is constrained to your configured `snapshots.*.connection` names — typos 404 instead of mounting an empty dashboard.

#### Per-connection authorisation (optional)

Add the `viewQueueInsightsConnection` Gate to authorise per connection:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('viewQueueInsightsConnection', function ($user, string $connection): bool {
    return $user->canAccessTenant($connection);
});
```

When defined, the dashboard:

- 403s direct visits to `/queue-insights/{connection}` the user can't access.
- Hides denied connections from the tab strip.
- Renames the "All" tab to "All allowed" with a tooltip listing only the connections the user can already open (denied tenants are never named).

If the gate isn't defined, every monitored connection is reachable to anyone who passes `viewQueueInsights` — same behaviour as pre-spec versions.

#### Audit log carries scope

Every retry log line (`queue-insights.retry`) includes `scope_connection` alongside the existing filter snapshot, so retries that span tenants are distinguishable from scoped retries.

#### Upgrade note — per-connection class metrics need traffic to warm

Per-connection class counters (`processed:{class}:{connection}:{bucket}`, `failed:{class}:{connection}:{bucket}`, `duration:{class}:{connection}`, `last_run:{class}:{connection}`, `classes:{connection}` zset) are dual-written alongside the existing aggregate keys. Aggregate dashboards (`/queue-insights`) render correctly from second 0 after upgrade. Scoped views (`/queue-insights/{connection}`) for per-class p95 / throughput / 24h totals fill in as new events flow — the first hour after deploy will show `0` for class counts on a scoped view. Aggregate keys are unchanged so rolling back the package version is safe.

#### Known limitations under scope

These v1 gaps surface only on the connection-scoped routes; the un-scoped dashboard is unaffected.

- **Heterogeneous batches are first-write-wins.** A `Bus::batch([...])` whose member jobs span multiple connections is indexed under the connection that dispatched the FIRST job. Other connections' scoped views won't see the batch. The detail/items view under scope reads `qi:batch-uuid-conn:{uuid}` (a dedicated uuid → connection side-key written when the job is queued, lifetime = `batches.ttl_seconds`) so cross-connection member uuids stay filtered even after the member has been processed/failed. Members past `batches.ttl_seconds` from queue time pass through. Operators relying on heterogeneous batches can fall back to the un-scoped view, which still shows every batch.
- **Recent completed list under a class drilldown post-filters by connection.** When the operator selects a class on a scoped view (`?class=App\\Foo` on `/queue-insights/redis`) the read routes to the per-class stream and post-filters rows by their `connection` field. The class stream caps at 1000 entries so the post-filter is cheap, but in extreme traffic skews a class drilldown may show fewer rows than the un-scoped class view. The plain scoped Recent completed list (no class drilldown) reads the dedicated `qi:completed:connection:{c}` stream and is unaffected.
- **Per-connection counter dual-write isn't atomic.** Aggregate and per-connection counters are written as separate Redis commands. A listener crash mid-write can leave the per-connection counter behind aggregate; later traffic re-fills it. Same best-effort guarantee the package's existing listeners offer; never produces phantom data.

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
- Every retry writes an `info`-level log line with channel `queue-insights.retry`, including the user id, the active filter set, and `scope_connection` (the multi-connection scope, when set). Forward that to your audit log.

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

### Batches

The dashboard renders a top-level **Batches** section above the Queues panel for jobs dispatched via `Bus::batch([...])->dispatch()`. Each row shows the batch name (or `Batch <short-id>` when unnamed), a progress bar driven by Laravel's authoritative `Bus::findBatch()` counts, and a counts triplet (`processed/total · failed · pending`). Cancelled batches show a red `cancelled` chip; finished + no-failures show a gray `finished` chip; jobs that fail when `allowFailures()` is off render `cancelled (first failure)` even before Laravel stamps `cancelled_at`.

Expanding a row reveals the per-uuid item list in enqueue order, with a status icon (✓ processed / ✗ failed / ⌛ pending) per item. Clicking a completed item opens the existing completed-job modal (by stream id); clicking a failed item opens the failed-job modal (by `failed_jobs.id`). The expand state is URL-shareable (`?batch=<batchId>`).

Every completed, failed, and pending row that belongs to a batch carries a small batch chip — clicking it opens the batch modal directly. The chip also renders inside the completed/failed/pending modal heroes, so an operator drilling into a single job can jump to its batch in one click. Inside an item modal that was opened from a batch, a `← Back to batch` button in the header returns you to the batch view without losing context (item modals stack visually on top of the batch modal).

The data is **event-captured into Redis** alongside Laravel's own `BatchRepository`. The `JobQueued` listener writes three keys per batched job:

- `qi:batches:index` (sorted set) — recent batchIds, ordered by first-seen unix timestamp. Used to enumerate batches without `SCAN`. Score-pruned on every enqueue (no whole-key TTL) so the head doesn't accumulate forever.
- `qi:batches:index:{connection}` (sorted set) — per-connection roster, populated first-write-wins via Lua so a heterogeneous batch lands on exactly one connection. Same score-pruning as the aggregate index. Read by `/queue-insights/{connection}` scoped views.
- `qi:batch:{id}:connection` (string) — single arbiter for first-write-wins. The atomic `SET … NX` on this key gates the per-connection ZADD inside `BatchClaimConnection.lua`. TTL is refreshed on every subsequent JobQueued for the same batch so the pointer doesn't age out under continued traffic.
- `qi:batch-uuid-conn:{uuid}` (string) — uuid → connection side-key written for every batched job. Survives the JobProcessed/JobFailed pending-hash deletion so the heterogeneous-batch detail-view scope filter keeps working after members have run.
- `qi:batch:{id}:uuids` (list) — RPUSH-ordered uuids in the batch. Bounded per batch by `batches.max_uuids_per_batch` (default 5000, best-effort under heavy concurrent dispatch).
- `qi:batch:uuid:{uuid}` (string) — reverse lookup uuid → batchId, used to render the per-row chip on completed jobs.

`RecordJobProcessed` and `RecordJobFailed` add two more per-uuid index keys (`qi:uuid-completed:{uuid}` and `qi:uuid-failed:{uuid}`) so the per-item rollup can route clicks into the existing modal flows.

Bounded storage:

- ~50 bytes per uuid (`qi:batch:{id}:uuids` entry + `qi:batch:uuid:{uuid}` reverse pointer + index entry, amortised per batch).
- TTL on every per-batch key (`batches.ttl_seconds`, default 604800 = 7d). Self-pruning on the index via `ZREMRANGEBYSCORE` on each enqueue; per-batch keys age out via Redis EXPIRE.
- Authoritative counts (`pending_jobs`, `processed_jobs`, `failed_jobs`, `progress`, `finished_at`, `cancelled_at`) come from `Bus::findBatch()` on every render — the captured keys exist only to enumerate batches and resolve uuid → display row, NOT to count.

**Retry caveat.** `queue:retry` and `queue:retry-batch` use `Queue::pushRaw()`, which does NOT fire `JobQueued`, so a retried job won't refresh as a fresh pending entry in the per-item rollup. The retry will still flow through `JobProcessed` (which DOES fire), so a successful retry overwrites `qi:uuid-failed:{uuid}` with `qi:uuid-completed:{uuid}` and the row flips from ✗ to ✓ within one poll cycle.

To opt out, set `QUEUE_INSIGHTS_BATCHES_ENABLED=false`. The listener writes become no-ops, the Batches section disappears, and chips stop rendering on existing rows.

### Chained jobs

Jobs dispatched through `Bus::chain([...])->dispatch()` (or `$job->chain([...])`) carry the remaining chain inside the serialized command body. The dashboard renders that forward chain context in two places:

- **List rows** — completed and failed rows that have a follow-up job render a small `↳ NextJob (+N)` chip, where the leaf-class name shows the immediate next job and `+N` counts the further-down-chain jobs after it. Hover reveals the full FQCN and the total chained count.
- **Modal Chain section** — the completed and failed modals include a `Chain` block with the next job's FQCN, the `+N more chained` count, and the chain's queue/connection (when set on the job). The block is clickable: it swaps the modal into a "Chained jobs" detail view that lists every chained link in order with per-link connection/queue, and a `← Back` button (or `Esc`) returns to the job view. Drilling into a single chained job inside the **failed-job modal** also surfaces its constructor properties (extracted from the serialized payload, framework internals filtered out) — same renderer used by the parent job's payload section. The completed-modal chain view stays metadata-only since the slim chain summary persisted on the stream entry doesn't retain user-bound data.

For **failed jobs** the source is `failed_jobs.payload.data.command` — Laravel always persists this column, so chain context renders regardless of the package's `capture.payloads` setting. For **completed jobs** the listener writes a JSON-encoded `chain` field (a list of `{class, connection, queue}` per chained link, typically ~80–300 bytes) onto each completed-stream entry at the time the job runs, also independent of `capture.payloads`. Per-link `connection`/`queue` overrides set on individual jobs are preserved — the displayed route reflects what Laravel will actually dispatch to. Encrypted jobs (`ShouldBeEncrypted`) carry an opaque base64 blob in `data.command`, so the chip and section are silently omitted for those rows — no error, just no signal.

**Backward chain visibility — `↰ From {parent}`.** As the parent enters processing, the package drops a short-lived **claim ticket** into Redis (per-shape FIFO list keyed by connection/queue/next-class/tail-fingerprint, default 60 s TTL). When the next link's `JobQueued` fires inside `CallQueuedHandler::call()`, the listener pops a ticket and stamps the parent's UUID onto the child's lineage hash. The completed-modal then renders `↰ From {uuid}` above the existing `↳ Next` row, and the failed-job markdown export gains a `**Parent:** \`{uuid}\` ({class})` line so AI-assisted triage can trace upstream of the failure point.

- **Disable** via `QUEUE_INSIGHTS_CHAIN_LINEAGE=false` (or `chain_lineage.enabled = false`). Both write and read sides short-circuit at the listener entry — zero Redis writes, zero overhead.
- **Encrypted parents (`ShouldBeEncrypted`) are silently skipped on both sides** — the serialized command body is opaque base64, so neither the parent's chain context nor the child's tail can be decoded. The child renders without a parent attribution; document this limitation if you mix encrypted chains with the dashboard.
- **Cross-worker collision tolerance.** Two parents with identical chain shape (same connection/queue/next-class/remaining-tail) running concurrently on different workers can attribute their children to each other in dispatch order rather than dispatch identity. Within a single worker chain dispatch is synchronous, so attribution is exact. Acceptable for an observability tool — see `internal/specs/backward-chain-lineage.md` §3 for the full collision model.
- **Class label is best-effort.** `qi:class:{uuid}` (TTL = `chain_lineage.lineage_ttl_seconds`, default 7 d) is the index that hydrates a parent UUID to a class name in the markdown export and modal. Past that horizon the UUID still renders, just without `(ClassName)`.
- **Click-through to the parent's modal is not in v1** — the lineage row is plain text plus a copy-to-clipboard button. Resolving a UUID to its target surface (completed stream id vs failed_jobs id) is a follow-up.

`queue:retry` re-runs a failed job through the normal worker path, so the eventual completed-stream entry of a retried chained job will still carry the correct `chain` field — the retry doesn't lose chain visibility. Backward lineage is keyed by uuid and survives the retry too: the existing `qi:lineage:{uuid}` is never overwritten with null.

### Customising row markup

The dashboard's queue, completed, and failed lists are each rendered through a Blade partial, plus a shared filter-form partial. They're publishable — a host that wants to swap a row's columns or restyle the filter chrome can publish the partials and edit them in place without forking the whole `dashboard.blade.php` view:

```bash
php artisan vendor:publish --tag=queue-insights-views
```

| Partial                              | What it renders                                                       |
|--------------------------------------|-----------------------------------------------------------------------|
| `partials/queue-row.blade.php`       | One row in the Queues list (Needs attention + Healthy groups)         |
| `partials/completed-row.blade.php`   | One row in Recent completed                                           |
| `partials/failed-list-row.blade.php` | One row in Recent failed                                              |
| `partials/batch-row.blade.php`       | One row in the Batches section (header + per-item rollup)             |
| `partials/batch-chip.blade.php`      | The small chip rendered on rows that belong to a batch                |
| `partials/filter-form.blade.php`     | The collapsible 5-field filter form (used by both completed + failed) |
| `partials/stat-tile.blade.php`       | One tile in the headline-stats panel beside the throughput sparkline  |

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

To embed a connection-scoped view, pass the scope as a mount param:

```blade
@livewire('queue-insights-dashboard', ['connection' => $tenant->queueConnection])
```
    
The component validates the connection against the configured snapshots (404s on mismatch) and runs `viewQueueInsightsConnection` defensively, same as the bundled route — so this is safe to render in publicly-reachable views.

### Custom payload sanitizer

The default `KeyRedactingSanitizer` can't see inside PHP-serialized `data.command` bodies. Apps with sensitive jobs should bind their own:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;

$this->app->bind(PayloadSanitizer::class, YourSanitizer::class);
```

## Running workers

`php artisan queue-insights:work` is a thin parent supervisor that reads `queue-insights.snapshots`, groups entries by connection, and spawns one `queue:work` subprocess per connection with `--queue=q1,q2,...` (Laravel's built-in priority list).

```bash
# Boot every monitored connection. One process per (connection, queue list).
php artisan queue-insights:work

# Restrict to one connection, e.g. when running per-connection systemd units.
# Both forms compose; they accept repeated flags AND comma-separated values.
php artisan queue-insights:work --connection=sqs
php artisan queue-insights:work --connection=sqs,redis
php artisan queue-insights:work --connection=sqs --connection=redis

# All `queue:work` flags forward verbatim to every child.
php artisan queue-insights:work --tries=5 --timeout=90 --memory=256 --max-jobs=1000
```

The supervisor owns argv assembly + signal forwarding + exit-code propagation. SIGTERM/SIGINT/SIGQUIT received by the parent are forwarded to every live child; after `queue-insights.work.shutdown_grace_seconds` (default 120) any survivors get SIGKILL with a stderr warning. Parent exit code is the **first** non-zero child's, or `128 + signum` for signal-initiated stops (Bash convention — lets systemd / supervisord distinguish operator-stop from supervisor-crash).

Output is line-prefixed with `[{connection}]` so `journalctl` / `docker logs` consumers can `grep` by connection without log shipping.

### Non-goals

This is **not** a Horizon replacement. The command is intentionally bounded to "one command, every monitored queue, one process group." Out of scope:

- **Auto-restart on crash** — host process manager owns liveness (systemd `Restart=on-failure`, supervisord, docker `restart: unless-stopped`).
- **Worker pool sizing / autoscaler** — one process per connection. Operators who want N workers per connection run N units with `--connection=X`.
- **Worker-liveness Redis keys + dashboard panel** — the existing `snapshot_command_dead` watchdog covers the snapshotter; no `qi:workers:*` heartbeat.
- **Cross-connection priority** — not possible while children are separate processes. Within-connection priority works (comma-list `--queue=q1,q2,q3`).
- **Per-queue flag overrides** — every child gets the same `--tries`, `--timeout`, etc. Per-queue sizing requires separate `--connection=X` units.

### Runtime requirements

- Requires the `pcntl` extension. POSIX hosts without it (and Windows generally) refuse to boot — the supervisor would otherwise orphan its children on shutdown.
- `queue:restart` works transparently — children share Laravel's global `illuminate:queue:restart` cache key reader.
- Pre-deploy ritual is unchanged: run `php artisan queue:restart` after a deploy, every child picks it up independently.

### `shutdown_grace_seconds` tuning

The default 120s covers `--timeout=60` + 20s SQS long-poll + headroom. The window must be **strictly greater than** the largest child `--timeout` plus driver poll latency (SQS long-poll = 20s, redis BLPOP up to 5s) — otherwise SIGKILL races a still-draining job. Bump it if you raise `--timeout`.

```php
// config/queue-insights.php
'work' => [
    'shutdown_grace_seconds' => 120,
],
```

## Ops runbook

### Dashboard signals

| Signal                     | Meaning                                                                                                             |
|----------------------------|---------------------------------------------------------------------------------------------------------------------|
| `—` on in-flight / delayed | Driver can't produce the metric (Null / sync), or the live cache expired (>90s since the last successful snapshot). |
| `stale` badge              | No snapshot ran in the last 2 minutes.                                                                              |
| `error` badge              | Last snapshot run failed for this queue. Hover for the error message (10-minute TTL).                               |
| `no snapshot yet`          | The command has never completed successfully against this queue.                                                    |

### Driver-specific quirks

- SQS values are AWS approximations. `GetQueueUrl` is cached for 1h in Redis; the first run per new queue name costs one extra API call.
- Redis reads `LLEN queues:{name}` plus `ZCARD` on `:reserved` and `:delayed`. Matches Laravel's own queue key convention.
- Database depth includes rows whose reservation has expired (crashed workers leave their jobs poppable again). Matches `DatabaseQueue::getNextAvailableJob()` exactly.

### Key-prefix strategies

- Shared Redis (multi-tenant, or multiple apps or envs on the same Redis): keep the default `QUEUE_INSIGHTS_KEY_PREFIX=qm:{APP_ENV}:`. Safe against collision.
- Dedicated Redis: override to `QUEUE_INSIGHTS_KEY_PREFIX=qm:` to drop the env segment and shorten every key.

### Alerting

Enable via `QUEUE_INSIGHTS_ALERTS_ENABLED=true`. Seven detectors run every snapshot tick (≈ every minute) against live Redis state:

| Rule               | Scope     | Fires when                                                                                                                |
|--------------------|-----------|---------------------------------------------------------------------------------------------------------------------------|
| `depth`            | per-queue | `live:depth` ≥ a configured threshold                                                                                     |
| `stalled`          | per-queue | depth ≥ `min_depth` AND no worker pickups in `idle_seconds`                                                               |
| `oldest_pending`   | per-queue | the oldest runnable pending job has been waiting `seconds` (skips not-yet-due delayed jobs)                               |
| `stuck_inflight`   | per-queue | the longest-running in-flight job has been executing `seconds`                                                            |
| `failure_rate`     | per-class | `failed / (processed + failed)` ≥ `ratio` over the **current hour bucket** AND total ≥ `min_jobs`                         |
| `slow_p95`         | per-class | per-class p95 duration ≥ `class_threshold_ms[$class]` (opt-in per class)                                                  |
| `snapshot_errored` | per-queue | the snapshot driver threw on the most recent tick (auto-clears on next success / 10-min TTL)                              |
| `backlog_growing`  | per-queue | least-squares depth slope over the recent samples ≥ `min_slope_per_minute` (opt-in, warms up after `min_samples` samples) |

A dashboard-only watchdog (`snapshot_command_dead`) renders a top-level red banner when `live:depth` keys are absent for every configured queue — i.e. the snapshot command itself has been silent for ≥ 90 s.

Cooldown applies to **outbound notifications only** (key: `alert:cooldown:{rule}:{c}:{q}`, TTL `cooldown_seconds`). The dashboard always reflects live state.

#### Config example

```php
// config/queue-insights.php
'alerts' => [
    'enabled' => env('QUEUE_INSIGHTS_ALERTS_ENABLED', false),
    'cooldown_seconds' => 900,

    'rules' => [
        'depth' => [
            'enabled' => true,
            // Multiple thresholds matching the same (connection, queue) →
            // highest matching severity wins per tick.
            'thresholds' => [
                ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000, 'severity' => 'warning'],
                ['connection' => 'sqs', 'queue' => 'work', 'depth' => 5000, 'severity' => 'critical'],
            ],
        ],
        'stalled' => ['enabled' => true, 'idle_seconds' => 120, 'min_depth' => 1, 'severity' => 'critical'],
        'oldest_pending' => ['enabled' => true, 'seconds' => 600, 'severity' => 'warning'],
        'stuck_inflight' => ['enabled' => true, 'seconds' => 300, 'severity' => 'warning'],
        'failure_rate' => ['enabled' => true, 'min_jobs' => 20, 'ratio' => 0.10, 'severity' => 'warning'],
        'slow_p95' => [
            'enabled' => false,
            'class_threshold_ms' => ['App\\Jobs\\GenerateReport' => 30_000],
            'severity' => 'warning',
        ],
        'snapshot_errored' => ['enabled' => true, 'severity' => 'warning'],
        'backlog_growing' => [
            'enabled' => false,
            'min_slope_per_minute' => 50.0,
            'min_samples' => 5,
            'severity' => 'warning',
        ],
    ],

    'channels' => [
        'log' => ['enabled' => true, 'level' => 'warning'],
        'slack' => ['enabled' => false, 'webhook_url' => env('QUEUE_INSIGHTS_SLACK_WEBHOOK')],
        'mail' => ['enabled' => false, 'to' => ['ops@example.com']],
    ],
],
```

> **Heads up — `oldest_pending` / `stuck_inflight` need pending tracking.**
> Both detectors read `pending-zset:*` / `inflight-zset:*` populated by the `RecordJobQueued` / `RecordJobProcessing` listeners. With `pending.enabled = false` they short-circuit at runtime and a one-off boot warning lists which rules were tripped. Either re-enable pending tracking or disable those rules.

#### Notification channels

The package ships three channels out of the box:

- **`log`** — zero-dep, on by default; one structured log line per issue at the configured level (`alerts.channels.log.level`).
- **`slack`** — `Http::post` to a Slack-compatible incoming webhook (works with Slack, Mattermost, Rocket.Chat). Block Kit payload with severity-coloured attachment; falls back to plain `text` if the receiver rejects Block Kit. Set `QUEUE_INSIGHTS_SLACK_WEBHOOK` and `alerts.channels.slack.enabled = true`.
- **`mail`** — uses Laravel's first-party mail channel; subject prefix `[Queue Insights] {severity}: {rule} on {target}`. Recipients from `alerts.channels.mail.to` (array of addresses).

Both `slack` and `mail` feature-detect the underlying binding (`Illuminate\Http\Client\Factory` and `mail.manager` respectively) — if the binding is missing they're silently skipped.

#### Adding more channels (Discord, Teams, PagerDuty, Telegram, …)

The package emits a `SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification` and routes it through `SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable`, exactly as Spatie's alerting packages and Horizon do. To add a destination:

1. Install the matching `laravel-notification-channels/*` package (`discord`, `microsoft-teams`, `pagerduty`, `telegram`, `vonage`, …).
2. Extend `QueueAlertNotification` to add the channel to `via()` and a `to{Channel}()` method, OR override `QueueInsightsNotifiable` and add `routeNotificationFor{Channel}()`.
3. Bind your override in your `AppServiceProvider`:

   ```php
   $this->app->bind(QueueAlertNotification::class, MyQueueAlertNotification::class);
   $this->app->bind(QueueInsightsNotifiable::class, MyNotifiable::class);
   ```

#### Typed events (always fire)

Each rule fires a typed event regardless of which channels are enabled — host apps can hook `Event::listen(...)` for custom routing:

- `QueueDepthExceeded` (existing — added trailing nullable `?string $severity`)
- `QueueStalled`, `OldestPendingAging`, `StuckInFlight`, `SnapshotErrored`
- `JobClassFailureRateExceeded`, `JobClassP95Exceeded`
- `BacklogGrowing`

#### Active-rules panel

The dashboard footer renders a read-only summary of `alerts.rules` + `alerts.channels` so operators can verify what's monitored without SSH'ing into the server. Edit the config file to change anything — there is no runtime mutation surface.

#### Migrating from the 0.x `alerts.thresholds` shape

The pre-1.0 config exposed a single flat `alerts.thresholds` list. It is still honoured (legacy wins over `alerts.rules.depth.thresholds`) and emits a one-off boot warning. To migrate:

```diff
 'alerts' => [
     'enabled' => true,
     'cooldown_seconds' => 900,
-    'thresholds' => [
-        ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000],
-    ],
+    'rules' => [
+        'depth' => [
+            'enabled' => true,
+            'thresholds' => [
+                ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000, 'severity' => 'warning'],
+            ],
+        ],
+    ],
 ],
```

Note: Laravel's `mergeConfigFrom` is a shallow merge, so hosts that published `config/queue-insights.php` before this version will not pick up the new nested defaults under `alerts.rules.*` automatically — copy the new keys from the package config when migrating.

#### Silencing noisy jobs

Mirrors Horizon's `horizon.silenced` knob: list job-class FQCNs whose **failures** should be suppressed from the dashboard's Failed list, the headline failed-tile, the throughput sparkline's failed series, the `failure_rate` alert detector, and outbound notifications.

```php
'silenced' => [
    App\Jobs\IntermittentlyFailingJob::class,
    App\Jobs\ThirdPartyApiSometimesFlakes::class,
],
```

Counter writes (`qi:processed:{class}:{bucket}`, `qi:failed:{class}:{bucket}`, `qi:classes`) are preserved — silencing is a read-side filter only, so removing a class from the list immediately re-surfaces its history without any backfill. The class rows table keeps showing throughput / p95 / max for silenced classes with a muted `silenced` badge so you can still triage them.

| Surface | Behaviour under silencing |
|---|---|
| Failed list (Failed tab) | Hidden by default. The "Show silenced" checkbox on the failed-pane filter form reveals them; URL-shareable as `?fs=1`. |
| Headline `failed_past_hour` + throughput sparkline failed series | Silenced classes excluded. Processed series stays exact. |
| `failure_rate` alert detector | Returns null for silenced classes — no event, no notification, no cooldown burned. |
| `slow_p95` alert detector | Unchanged — silencing is a failure-noise filter, not a perf filter. Exclude noisy classes from `class_threshold_ms` if you want their perf alerts muted too. |
| Class rows table | Row stays, marked with a muted `silenced` badge inline next to the FQCN. Operators still see throughput / p95 / max for silenced classes. |
| Modal-by-uuid + chain-lineage click-through + batch-detail items | NOT filtered. Silencing is a list-level filter; uuid-addressed lookups always resolve so a batched member or chain parent stays clickable. |
| `qi:failed:{class}:{bucket}` Redis counters + `qi:classes` zset | Still written by the listeners. Silencing is reversible without losing history. |

The bulk-retry uuid collector inherits the same SQL exclusion path — bulk-retry actions on the default-filter view never queue silenced classes for retry. Toggle "Show silenced" first if you want them in the bulk set.

## License

MIT. See [`LICENSE`](LICENSE).
