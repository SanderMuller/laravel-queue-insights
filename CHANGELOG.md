# Changelog

All notable changes to `laravel-queue-insights` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## 0.5.0 - 2026-04-28

Minor release. Two user-visible UX changes — a tabbed dashboard with an Overview pane and server-side pagination on Completed/Failed — plus an internal cleanup sprint that decomposes the Livewire dashboard component into focused, testable support classes.

No public API breaks. No config changes, no schema, no published-asset moves.

### What's new

#### Tabbed dashboard with an Overview pane

The dashboard reorganises into six tabs — Overview (default), Queues, Pending, Batches, Completed, Failed — anchored by a sticky tab strip. The active tab persists in `window.location.hash` (`#qi-overview`, `#qi-queues`, …) so refreshes and bookmarks land where the operator left off.

The new Overview pane is a 4-card mission grid:

- **Queues card** — top-N queues (at-risk first, padded by deepest), backlog + in-flight totals, "needs attention" / "all healthy" status pill.
- **Pending card** — top-N rows across in-flight / pending-now / delayed (in-flight tagged so the dot pulses), with a sub-counter row.
- **Recent completed card** — top-5 most-recent completions with the past-hour throughput badge.
- **Recent failed card** — top-5 most-recent failures with the past-hour failure badge.

Each card row is the same clickable + keyboard-accessible affordance as the full table — opening the same modal — and a "See all N →" footer button switches to the matching tab via the URL hash.

The persistent hero (sparkline + 6-KPI panel) sits above the tab strip so the throughput trend stays visible across tabs.

#### Server-side pagination on Completed and Failed

Completed and Failed lists paginate at 25 rows per page. Page state lives in URL-shareable Livewire props (`?cp=`/`?fp=`) so a deep-linked page survives refresh; bookmarking page 5 of a list that's since shrunk to 2 pages clamps gracefully to the last available page. Filter changes auto-reset to page 1 via a single `updated()` hook keyed off prop-name prefix.

Pagination paginates over the most-recent 250-row window per tab (10 pages of 25). Older history is not paginated by design — the dashboard remains a recency view, not a historical archive.

#### Compact metadata pills + unified row scaffolding

The metadata pill (`Connection: redis`, `Queue: default`, `ID: <uuid>`) used to be a divergent two-half `<dl>` open-coded fifteen times across the four modal components. The styling-drift bug where the `<dd>` half rendered transparent (`bg-gray-950/[0.04]` dt + `bg-white` dd → recent UI noise) is fixed and the markup lifted into a single component:

```blade
<x-queue-insights::meta-pill label="Connection" :value="$payload['connection'] ?? null"/>
<x-queue-insights::meta-pill label="Queue" :value="$payload['queue'] ?? null" size="sm"/>

```
A second new component, `<x-queue-insights::list-row>`, owns the four main row partials' `role="button"` + `tabindex` + keyboard handler scaffold — one place to fix click + a11y wiring instead of four.

#### Modal stacking-context fix

The four modal overlays (details / failed / pending / batch) gain `z-50` on the `fixed inset-0` wrapper so the modal sits above any portaled UI regardless of source order. Existing modal interaction is unchanged.

#### Workbench preview — `openBatch` + paged seed data

The workbench `PreviewDashboard` mirrors production's `openBatch` cross-modal navigation and pads its seed data so the preview exercises multi-page pagination state.

### Internal — `Dashboard\` namespace + `DashboardData` orchestrator

Six `@internal` classes consolidate logic that used to live inline inside the 964-line Livewire component:

| Class | Role |
|---|---|
| `Dashboard\DashboardData` | Orchestrator. `build($component)` returns the full view-data array; the component's `render()` is now a one-liner. Owns `PER_PAGE` (25) and `RECENT_FETCH_LIMIT` (250). `EXPECTED_KEYS` enumerates the contract. |
| `Dashboard\ModalResolver` | Resolves the open modal target (payload / failed / pending / batch). Pure scans for the first two; `findPendingByUuid` + `BatchReader::detailRow` fallbacks for the latter two so deep-linked selections outside the loaded window still mount. |
| `Dashboard\HeadlineStatsBuilder` | jobs/min, past-hour totals, max wait, max runtime — one shape, derived from data already loaded. |
| `Dashboard\FilterOptionsBuilder` | Connection/queue/class option lists for the filter dropdowns. |
| `Dashboard\ClassRowsBuilder` | Per-class row set with 24h aggregate metrics. |
| `Dashboard\QueueRowsBuilder` | Per-queue rows with live depth/in-flight/delayed, staleness flag, wait percentiles, pending-inspector fields. |

Two more `@internal` Support classes lift cross-cutting helpers:

- `Support\QueueAggregates` — `aggregate()` partition + total, `queuePreview()` + `pendingPreview()` for the Overview cards.
- `Support\FailedJobUuidCollector` — pluck the bulk-retry uuid set; lives outside the Livewire component on purpose so the query isn't part of the client-callable action surface.

`Support\WaitTimeMetrics::format(?int)` — public formatter for ms → human strings. The dashboard exposes it as a `$fmtMs` callable in view data.

The Livewire component shrinks from 964 → ~510 LOC. `render()`:

```php
public function render(DashboardData $data): View
{
    return ViewFactory::make('queue-insights::dashboard', $data->build($this));
}

```
### Internal — view decomposition

`tabs-workspace.blade.php` (formerly 507 lines hosting six panes) splits into:

```
resources/views/partials/
├── tabs-workspace.blade.php       (~80 LOC: tab strip + Alpine state + 6 @includes)
├── persistent-hero.blade.php
├── pagination-controls.blade.php
├── card-mini-row.blade.php
└── tabs/
    ├── pane-overview.blade.php
    ├── pane-queues.blade.php
    ├── pane-pending.blade.php
    ├── pane-batches.blade.php
    ├── pane-completed.blade.php
    ├── pane-failed.blade.php
    └── tab-button.blade.php

```
`dashboard.blade.php` is now a 44-line shell: flash banner, hero, tabs-workspace, modal mounts. The 47-line `@php` derivation block at the top of `dashboard.blade.php` is gone — `$queuePreview`, `$pendingPreview`, `$totalDepth`, `$totalInFlight`, `$atRisk`, `$healthy`, `$fmtMs` are computed in the component layer.

### Diagnostics noise floor

- Drop unused `use Illuminate\Support\Facades\Redis` from the dashboard component.
- Rename the unused override param `$artisan` → `$_artisan` in `tests/Support/RecordingConsoleKernel`.
- Add the `int` type to the `PER_PAGE` typed-constant.

### Public API surface (additive)

- `<x-queue-insights::meta-pill>` — new publishable Blade component.
- `<x-queue-insights::list-row>` — new publishable Blade component.
- `Support\WaitTimeMetrics::format(?int $ms): string` — new public static formatter.

All `Dashboard\*` and the new `Support\QueueAggregates` / `Support\FailedJobUuidCollector` classes are `@internal` and not part of the supported surface.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.4.1...0.5.0

## 0.4.1 - 2026-04-28

### Bug fixes

#### Failed-jobs class filter returned 0 results on MySQL

`Recent failed → filter → class → pick any` came back empty even when matches existed. Root cause was a backslash-in-LIKE collision inside `applyFailedJobFilters`:

- `addslashes('App\Jobs\Foo')` doubled each `\` to `\\`.
- MySQL's default LIKE escape (`\`) then consumed the doubled backslash back to a single `\`.
- The pattern looked for `App\Jobs\Foo` while the JSON column actually stored `App\\Jobs\\Foo` (json_encode persists `\` as `\\`). No match.

Fix: derive the needle from `json_encode($filters->class)` so it produces the exact byte sequence stored in the column, plus `ESCAPE '|'` so the LIKE engine treats `|` (not `\`) as the escape char — portable across MySQL, PostgreSQL, and SQLite. `LOWER()` stays on both sides so deep-linked URLs with mismatched casing still match (without it, PostgreSQL's case-sensitive LIKE would silently miss while MySQL/SQLite matched). User-supplied `%` / `_` / `|` are escaped so a hostile FQCN can't smuggle a wildcard match.

Three new regression tests cover backslash matching, mixed-case input, and wildcard escape.

#### Boundary-case test flake on the prefer-stable cell

The `pending vs delayed by available_at <= now boundary` test seeded `d1 = now + 1` and asserted it landed in `delayedJobs()` only. On a slow runner a second-rollover between the test capturing `$now` and `QueueInsights::pendingJobs()` re-reading `Date::now()` flipped the boundary case into the pending bucket. Pinned via `Date::setTestNow()` so both reads see the same timestamp.

### What's new

#### Sentry-style nested-data renderer for Other fields

`illuminate:log:context` and any other non-standard top-level payload key whose value is a nested array used to render as a single opaque JSON-blob line with truncate-and-expand. The completed-job and failed-job payload Raw tab now drills container values through a recursive tree component:

- Container header summarises shape (`object · 3 keys` / `array · 12 items`).
- Click-to-expand chevron, key/value rows, scalars inline.
- Depth-capped at 6 to bound DOM weight on pathological inputs.
- Uses `<template x-if>` (not `x-show`) so collapsed subtrees never materialize into the DOM — browser skips layout/style cost on hidden branches.
- XSS-safe: keys and values flow through Blade's `{{ }}` auto-escape.

Reuses the same component pattern (`serialized-properties`) operators are already familiar with from the Job-instance panel, so no new mental model.

#### Public API surface (additive)

- `<x-queue-insights::nested-data :data="$value">` — new publishable Blade component for rendering arbitrary tree-shaped data.

### Install / upgrade

If you've previously published the views (`php artisan vendor:publish --tag=queue-insights-views`), re-publish to pick up the new `nested-data` component, or rebase your fork onto the new file. The class-filter fix is in `src/`, no view re-publish required for that part.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.4.0...0.4.1

## 0.4.0 - 2026-04-27

Batches, in-flight, and chained-job inspector release. Closes the visibility gaps around `Bus::batch([...])`, jobs currently being processed, and `Bus::chain([...])` continuations — all driver-agnostic, all sourced from event capture so the same view works on Redis, database, and SQS. Cross-modal navigation lets operators move between a batch view, individual job modals, and the chain detail without losing context.

Drop-in upgrade from `0.3.x`. No schema, no API breaks. Two new opt-in defaults (`batches.enabled`, the new in-flight sub-section), each opt-out via one env flag.

### Highlights

#### Batches

Top-level **Batches** section above the Queues panel for jobs dispatched via `Bus::batch([...])->dispatch()`. Each row shows the batch name (or `Batch <short-id>` when unnamed), a progress bar driven by Laravel's authoritative `Bus::findBatch()` counts, and a counts triplet (`processed/total · failed · pending`). Cancelled batches show a red `cancelled` chip; finished + no-failures show a gray `finished` chip.

Clicking a row opens a batch modal with the per-uuid item list in enqueue order, status icon per item (✓ processed / ✗ failed / ▶ in-flight / ⌛ pending), and a `← Back to batch` exit. The expand state is URL-shareable (`?batch=<batchId>`).

Authoritative counts come live from `Bus::findBatch()` on every render — the package only stores the index/uuid-list/reverse-lookup needed to enumerate batches and resolve uuid → display row.

#### In-flight sub-section

Pending now / Delayed already existed; **In-flight** is the third sub-group above them, ordered longest-running-first via a dedicated `inflight-zset` so stuck jobs surface at the top. The pending → in-flight transition is wrapped in a Lua script (`MarkInFlight.lua`) so the dashboard never sees a job missing from both groups during the handoff. Each row shows when the worker picked the job up and how long it's been running; the modal flips to an in-flight variant with `Started` + `Running for` tiles.

#### Chained jobs

Jobs dispatched through `Bus::chain([...])->dispatch()` (or `$job->chain([...])`) carry the remaining chain inside the serialized command body. The dashboard surfaces it in two places:

- **List rows** — completed and failed rows that have a follow-up job render a small `↳ NextJob (+N)` chip. Hover reveals the full FQCN and the total chained count.
- **Modal Chain section** — the completed and failed modals include a `Chain` block with the next job's FQCN, the `+N more chained` count, and the chain's queue/connection. The block is clickable: it swaps the modal into a "Chained jobs" detail view that lists every chained link in order with per-link routing, and a `← Back` button (or `Esc`) returns to the job view.

Drilling into a single chained job inside the **failed-job modal** also surfaces its constructor properties (extracted at render time from the persisted serialized payload, framework internals filtered out) — same renderer used by the parent job's payload section. The completed-modal chain view stays metadata-only since the slim chain summary persisted on the stream entry doesn't retain user-bound data.

For **failed jobs** the source is `failed_jobs.payload.data.command` — Laravel always persists this column. For **completed jobs** the listener writes a JSON-encoded `chain` field on the stream entry at the time the job runs, also independent of `capture.payloads`. Per-link `connection`/`queue` overrides set on individual jobs are preserved. Encrypted jobs (`ShouldBeEncrypted`) carry an opaque base64 blob in `data.command`, so the chip and section are silently omitted for those rows — no error, just no signal.

#### Cross-modal navigation

Item modals (details / failed / pending) now stack on top of the batch modal instead of unmounting it. A `← Back to batch` button in the item modal header returns to the batch view without losing context. The batch chip — present on every completed/failed/pending list row — also renders inside the modal heroes, so an operator drilling into a single job can jump to its batch in one click. New `openBatch(string $id)` Livewire action handles the routing and closes any open item modal in the same round-trip.

Direct-by-uuid pending hydration + direct batch lookup as fallbacks: chips and links work for items that sit outside the top-50 aggregate window or for batches older than the section cap. Without these, an operator clicking a batched-job chip on a backed-up queue could land on a misleading "no longer pending" / "Batch no longer tracked" empty state even though the data was still tracked.

### Bug fixes

- **`RecordJobFailed` indexed the wrong row on retry-then-fail.** `DatabaseUuidFailedJobProvider::log()` inserts a fresh row on every JobFailed, so a uuid that retried and failed again has multiple rows. The prior `where('uuid', $uuid)->value('id')` returned the OLDEST row by query default order; clicking the batch-detail item then opened the stale failure. Now sorts by `id desc`.
- **`Batch::progress()` cross-Laravel parity.** Returns float on Laravel 11/12 (PHP `round()` defaults to float), int on Laravel 13. Cast to int in `BatchReader::projectBatch()` so the row-shape contract holds across the supported matrix.

### Public API surface (additive)

- `QueueInsights::recentBatches(int $limit = 50): array`
- `QueueInsights::batchDetail(string $batchId): ?array`
- `QueueInsights::allInFlightJobs(int $limit = 50): array`
- `QueueInsights::allPendingJobs(int $limit = 50): array` (cross-queue aggregator — was per-queue only)
- `QueueInsights::allDelayedJobs(int $limit = 50): array`
- `QueueInsights::findPendingByUuid(string $uuid): ?array`
- `Support\BatchReader` — new helper class
- `Support\RowEnricher` — new helper class (decode chain JSON, enrich completed/failed rows with batch_id + chain)
- `Support\Lua\MarkInFlight.lua` — new Lua script (atomic pending → in-flight transition)
- `Support\PendingJobsReader::findByUuid(string $uuid): ?array`
- `Support\SerializedCommandReader::extractChainContext(string $serialized): ?array` — now includes per-job `properties` map (framework internals filtered)
- `QueueInsightsDashboard::openBatch(string $id): void` — Livewire action
- `QueueInsightsDashboard::closeBatch(): void`
- `QueueInsightsDashboard::openPending(string $uuid): void`
- `QueueInsightsDashboard::closePending(): void`
- `QueueInsightsDashboard::toggleBatchInspector(string $id): void`
- `QueueInsightsDashboard::$expandedBatchId` — new `#[Url(as: 'batch')]` prop
- `QueueInsightsDashboard::$selectedPendingUuid` — Livewire prop
- New publishable Blade components: `batch-modal`, `pending-modal`, `hint`
- New publishable partials: `batch-row`, `batch-chip`, `pending-row`
- New config block:

```php
'batches' => [
    'enabled' => env('QUEUE_INSIGHTS_BATCHES_ENABLED', true),
    'max_uuids_per_batch' => 5000,
    'max_per_query' => 100,
    'ttl_seconds' => 604800,
],



```
### Storage cost

- Batches: ~50 bytes per uuid (per-batch list entry + reverse pointer + index entry, amortised). Per-batch keys TTL-aged via `batches.ttl_seconds` (default 7d). Index self-prunes via `ZREMRANGEBYSCORE` on each enqueue.
- In-flight zset: ~30 bytes per running job, cleared on JobProcessed/JobFailed.
- Authoritative batch counts come from `Bus::findBatch()` — the package's keys exist only to enumerate and resolve, not to count.

### Opt-out

- `QUEUE_INSIGHTS_BATCHES_ENABLED=false` — listener writes become no-ops, the Batches section disappears, chips stop rendering on existing rows.
- `QUEUE_INSIGHTS_PENDING_ENABLED=false` — also disables the in-flight sub-section (shares the same `pending:{uuid}` hash for state).

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.3.0...0.4.0

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
