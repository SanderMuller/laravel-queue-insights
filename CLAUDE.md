<package-boost-guidelines>
# Package Boost Guidelines

These guidelines replace Laravel Boost's default foundation for
repositories that ship as Composer packages — Laravel-targeted or
framework-agnostic. The framing, tooling, and trade-offs differ from
application development; follow this version when working inside a
package codebase.

## Foundational Context

This codebase is a **Composer package**, not an application. The rules
below hold regardless of which framework (if any) the package targets.

- There is no `app/`, `bootstrap/`, `routes/`, `.env`, or database by
  default. Tooling that assumes an application context (e.g. running
  `php artisan` against the package itself) does not apply.
- The primary artefact is the package's public API — entry-point
  classes, service providers, exposed contracts. Everything else is
  scaffolding.
- Downstream consumers depend on this package via Composer. Every
  public change is a user-facing API change governed by semver.
- `composer.json` is the source of truth for supported PHP versions
  and any framework constraints. Check `require.php` (and any
  `require.<framework>/*` entries) before using version-specific
  features.

## Source Layout

- `src/` — package source, PSR-4 autoloaded per `composer.json`
- `tests/` — Pest or PHPUnit suite
- `config/` — publishable defaults shipped with the package, when
  applicable
- `resources/` — views, translations, Boost skills / guidelines, when
  applicable
- `database/migrations`, `database/factories` — only if the package
  ships them
- `workbench/` — developer-only Testbench scaffolding when Testbench
  is in use; never shipped

Check sibling files before inventing structure. Do not introduce new
top-level directories without a clear reason.

## Tests Are the Specification

The package has no running application to click through. Tests are how
behaviour is pinned down.

- Write tests alongside any behavioural change.
- Do not create "verification scripts" when a test can prove the same
  thing.
- Run the project's configured test runner (`vendor/bin/pest` or
  `vendor/bin/phpunit`) before claiming a change is done.

## Public API Discipline

- Every `public`, `protected`, or exported symbol is part of the
  package's surface. Breaking changes require a major version bump.
- Prefer `final` classes and `private`/`@internal` markers for
  anything not intended for extension.
- Keep config keys, published asset paths, and service container
  bindings stable across patch and minor versions.

## Conventions

- Match existing code style, naming, and structural patterns — check
  sibling files before writing new ones.
- Use descriptive names (`resolvePublishDestination`, not `resolve()`).
- Reuse existing helpers before adding new ones.
- Do not add dependencies without approval; every new `require` is a
  constraint downstream consumers inherit.

## Documentation Files

Only create or edit documentation (README, CHANGELOG, docs/) when
explicitly requested or when a behaviour change requires it.

## Replies

Be concise. Focus on what changed and why. Skip restating what the
diff already shows.

## If your package targets Laravel

The rest of this document is Laravel-specific. Skip it if the package
is framework-agnostic — `composer.json` should make that obvious (no
`require.illuminate/*`, no `require.laravel/framework`).

### Laravel context

A Testbench-provided Laravel application is spun up only at test
time. Base test case is `Orchestra\Testbench\TestCase`.
`composer.json`'s `require.illuminate/*` (or
`require.laravel/framework`) defines the supported Laravel range —
check it before using version-specific framework APIs.

### Use `vendor/bin/testbench`, not `php artisan`

Running artisan commands directly against the package fails — there is
no host application. Use Testbench's binary:

| Instead of | Use |
|---|---|
| `php artisan test` | `vendor/bin/pest` or `vendor/bin/phpunit` |
| `php artisan tinker` | `vendor/bin/testbench tinker` |
| `php artisan make:*` | Create files manually under `src/` |
| `php artisan vendor:publish` | `vendor/bin/testbench vendor:publish` |

#### Commands that require `laravel/boost`

These only apply when the package has `laravel/boost` as a dev
dependency. Skip if Boost isn't installed — `package-boost:sync`
prints a warning and moves on.

| Instead of | Use |
|---|---|
| `php artisan boost:install` | `vendor/bin/testbench boost:install` |
| `php artisan boost:mcp` | `vendor/bin/testbench boost:mcp` |

Register the package's service provider in `testbench.yaml` under
`providers:` so Testbench boots it. Published files land in
`workbench/` by default, not `config/` or `resources/` of a host app.

### Cross-Version Compatibility

Supporting multiple Laravel / PHP majors is routine for Laravel
packages. Activate `cross-version-laravel-support` **before** writing
the code; activate `ci-matrix-troubleshooting` **after** a matrix cell
has failed.

---

# Alerting — internals + extension points

This is the AI-facing reference for the alerting subsystem. End-user docs live in `README.md` and `internal/specs/alerting.md`. This file is what you read **before** changing the alerting code.

## Detector catalogue (source of truth)

| Rule | File | Scope | Fires when | Reads |
|---|---|---|---|---|
| `depth` | `src/Alerts/Detectors/DepthDetector.php` | per-queue | `live:depth` ≥ a configured threshold (highest matching severity wins) | `live:depth:{c}:{q}` (90 s TTL) |
| `stalled` | `src/Alerts/Detectors/StalledDetector.php` | per-queue | depth ≥ `min_depth` AND `ZCOUNT wait:{c}:{q} now-idle_seconds +inf == 0` | `live:depth:{c}:{q}`, `wait:{c}:{q}` zset |
| `oldest_pending` | `src/Alerts/Detectors/OldestPendingDetector.php` | per-queue | oldest `available_at <= now` ≥ `seconds` | `pending-zset:{c}:{q}`, `pending:{uuid}` hash |
| `stuck_inflight` | `src/Alerts/Detectors/StuckInFlightDetector.php` | per-queue | oldest `started_at` ≥ `seconds` | `inflight-zset:{c}:{q}`, `pending:{uuid}` hash |
| `failure_rate` | `src/Alerts/Detectors/FailureRateDetector.php` | per-class | `failed/(processed+failed) ≥ ratio` AND total ≥ `min_jobs` (current hour bucket only) | `processed:{class}:{YmdH}`, `failed:{class}:{YmdH}` |
| `slow_p95` | `src/Alerts/Detectors/SlowP95Detector.php` | per-class | `lrange duration:samples:{class}` p95 ≥ `class_threshold_ms[$class]` | `duration:samples:{class}` list |
| `snapshot_errored` | `src/Alerts/Detectors/SnapshotErroredDetector.php` | per-queue | `EXISTS snapshot:error:{c}:{q}` (10-min TTL written by snapshot command's catch branch) | `snapshot:error:{c}:{q}` |
| `backlog_growing` | `src/Alerts/Detectors/BacklogGrowingDetector.php` | per-queue | least-squares depth slope ≥ `min_slope_per_minute` over the recent samples zset (opt-in, warms up after `min_samples`) | `samples:depth:{c}:{q}` zset (member `"{ts}:{depth}"`, score ts; cap 30; 2 h TTL) |
| `snapshot_command_dead` | `src/Alerts/SnapshotWatchdog.php` | global, **dashboard only** | no `live:depth:*` keys present for any configured queue | `live:depth:{c}:{q}` |

Each `*Detector::detect()` returns `?Issue` and is **pure** w.r.t. side effects — no cooldown, no events, no notifications. Cooldown + dispatch sit in `IssueDispatcher`. The dashboard reads via `ActiveIssuesProvider` (per-request memoise + 5 s Redis cache `alert:cache:active-issues`).

## Signal sources (write paths to verify before touching detector reads)

| Detector reads | Written by |
|---|---|
| `live:depth:{c}:{q}` | `Console\QueueInsightsSnapshotCommand::writeMetric` (`SETEX`, 90 s) |
| `wait:{c}:{q}` zset | `Listeners\RecordJobProcessing` line 87+ — **must** canonicalise queue key (`CanonicalQueueKey::from`); see Phase 2 finding in `internal/specs/alerting.md` |
| `pending-zset:{c}:{q}` | `Listeners\RecordJobQueued::writePendingTracking` (canonical key) |
| `pending:{uuid}` hash | same listener; fields `connection,queue,class,queued_at,available_at,batch_id,state,started_at` |
| `inflight-zset:{c}:{q}` | `Listeners\RecordJobProcessing::markInFlight` via Lua `markInFlight()` script (canonical key) |
| `processed:{class}:{YmdH}` / `failed:{class}:{YmdH}` | `Listeners\RecordJobProcessed` / `RecordJobFailed` |
| `duration:samples:{class}` | `Listeners\RecordJobProcessed` (RPUSH, capped at 500) |
| `snapshot:error:{c}:{q}` | `Console\QueueInsightsSnapshotCommand::recordError` (catch branch only, 600 s TTL) |
| `samples:depth:{c}:{q}` | `Console\QueueInsightsSnapshotCommand::writeDepthSample` (`ZADD` + cap-30 `ZREMRANGEBYRANK` + 7200 s `EXPIRE`; member `"{ts}:{depth}"`) |
| `qi:classes` zset | `Listeners\RecordJobProcessed` (last-seen score, pruned 30 d by snapshot command) |

When adding a new detector, the writer must already exist or you ship it in the same change. Do **not** invent a new key family for v1 of any rule — reuse the existing tables. New key families are a v2-grade migration (see §6 backlog-growing in the spec).

## Detect-vs-dispatch split

```
QueueInsightsSnapshotCommand
    ├── for each (connection, queue) pair:
    │   ├── snapshot driver → write metrics
    │   └── IssueDispatcher::dispatchForSnapshot(c, q, depth)
    │       └── IssueDetector::detectForSnapshot(c, q, depth)
    │           └── runs queue-scoped detectors (DepthDetector::detectWithDepth, ...)
    ├── catch path: IssueDispatcher::dispatchSnapshotError(c, q)
    └── after the loop: IssueDispatcher::dispatchClassScoped()
        └── IssueDetector::detectClassScoped($class) for each $class in qi:classes

QueueInsightsDashboard::render
    └── DashboardData::build
        ├── ActiveIssuesProvider::get  → IssueDetector::detectAll  (no cooldown, no notify)
        ├── SnapshotWatchdog::isSnapshotCommandDead
        └── AlertRulesPanelBuilder::build
```

Snapshot command path **always runs the detector fresh** so cooldown decisions reflect truth. Dashboard path **always reads the cache** (5 s TTL + per-request memoise) to bound thunder-herd across concurrent tabs.

## Cooldown — namespaced by rule

Key shape:

- queue-scoped: `alert:cooldown:{rule}:{c}:{q}`
- class-scoped: `alert:cooldown:{rule}:class:{class}`

Constructed by `Issue::cooldownKeySuffix()`. One rule's cooldown does NOT suppress another rule's alert on the same queue — keys are namespaced. Cooldown applies to **outbound notifications only**; the dashboard always reflects live state.

`Cooldown::acquire()` uses `SET key val EX ttl NX` via `RedisEval::exec` so the phpredis-vs-Predis option-shape divergence stays in one place.

## Notification routing — Spatie idiom

Built on `Illuminate\Notifications\Notification`. Key classes: `Alerts\Notifications\QueueAlertNotification` (via/toMail/toSlack), `Alerts\Notifications\QueueInsightsNotifiable` (routeNotificationFor*, `getKey()='queue-insights'`), `Alerts\Notifications\Channels\{LogChannel,SlackWebhookChannel}`. Both notification + notifiable are bound (not singleton) so hosts override via container. Optional channels live in `composer.json` `suggest`, never `require`.

Rationale + Phase 4 pivot history: `internal/specs/alerting.md` §Phase 4.

## Adding a custom detector

Most operator-driven asks are new detectors. Pattern:

1. Create `src/Alerts/Detectors/MyDetector.php` exposing `detect(string $connection, string $canonicalQueue): ?Issue` (queue-scoped) or `detect(string $class): ?Issue` (class-scoped).
2. Add the rule key + config defaults to `config/queue-insights.php` under `alerts.rules.*`.
3. Add validation to `Support\ConfigValidator::validateAlerts()` — every shipped rule has its own `validate{Rule}Rule()` method already; copy that template.
4. Inject the detector into `Alerts\IssueDetector` (constructor + `detectQueueScoped()` or `detectClassScoped()`).
5. Add a typed event class under `src/Events/` and wire it into `IssueDispatcher::fireEvent()` match expression.
6. Tests — feature test under `tests/Feature/AlertingDetectorsTest.php` (queue-scoped) or `AlertingClassDetectorsTest.php` (class-scoped) seeding fixture Redis state, plus a config-validator unit test.

For a non-Issue side effect (e.g. write a metric to Prometheus when an alert fires), prefer listening to the typed event in the host app rather than extending the dispatcher — keeps the dispatcher's blast radius bounded.

## Config migration — `mergeConfigFrom` shallow-merge caveat

`ServiceProvider::mergeConfigFrom` is a **shallow** merge. Consumers who published `config/queue-insights.php` before the `alerts.rules` migration will NOT pick up the new nested defaults — their published file's `alerts` array wins entirely. Three states the boot path handles:

1. New install (no published config) → package defaults apply.
2. Pre-existing published config with legacy `alerts.thresholds`, no `alerts.rules` key → **legacy wins**, deprecation logged on boot.
3. Pre-existing published config with both → **legacy wins**, deprecation logged.

Why legacy wins: hosts setting both are likely mid-migration with legacy still load-bearing; silently ignoring it risks losing prod alerts. Loud deprecation + legacy-wins is safer.

## What NOT to do

- Don't read the cache from the snapshot command. Cooldown decisions need fresh detector output every tick.
- Don't add a runtime config-mutation surface (admin UI / API to toggle rules). The active-rules panel is **read-only** by design — config is the source of truth, version-controlled, reviewable.
- Don't bypass `Cooldown::acquire()` for "important" alerts. The cooldown key is per-(rule, target) — if you want louder paging for a critical issue, set a shorter `cooldown_seconds` or wire an external pager (PagerDuty channel) that handles its own escalation.
- Don't extend `Issue` with rule-specific fields. The `context: array<string, mixed>` slot exists to keep the DTO stable across detectors. Strongly-typed events (`QueueStalled`, `OldestPendingAging`, …) are where rule-specific shape lives for host listeners.
- Don't make any detector depend on a new Redis key family without a migration plan. `backlog_growing` (Phase 7) is the only rule shipping its own samples zset (`samples:depth:{c}:{q}`) — and it ships the writer alongside the detector in the same change, never read-only.
- Don't add silencing logic in listeners or counter writers. Silencing is a **read-side filter only** — counter writes (`failed:{class}:{bucket}`, `qi:classes`) are preserved so `queue-insights.silenced` is reversible without losing history. If you need to extend silencing to a new surface, add the filter at the read path (detector entry, list builder, SQL query) and never at the writer.

## Silenced jobs

Read-side filter that drops silenced job-class **failures** from dashboard list/aggregate surfaces and the alert pipeline. Mirrors Horizon's `horizon.silenced`. Spec: `internal/specs/silenced-jobs.md`.

Write surfaces (listeners, Redis counters, `qi:classes` roster) are **never** filtered — silencing is reversible without backfill.

Touchpoints (read these before extending):

- `src/Support/SilencedJobs.php` — `app()->scoped()`-bound helper. `isSilenced(string)` / `all()` / `appendExclusion(Builder)`. Snapshots `queue-insights.silenced` once per request; Octane-safe via the scoped binding.
- `src/Support/DisplayNamePayloadMatch.php` — single-source `LOWER(payload) … ESCAPE '|'` pattern builder, shared between the include filter (class LIKE) and the silenced exclusion (NOT LIKE).
- `src/Support/ConfigValidator.php::validateSilenced` — list-shape + non-empty + relaxed class-label regex (allows `@:/` for synthetic `Closure@hash` / `Encrypted@hash` labels). Wired in `QueueInsightsServiceProvider::boot` **outside** the `alerts.enabled` gate (silencing affects dashboard reads regardless of alerts).
- `src/Alerts/Detectors/FailureRateDetector.php` — silence short-circuit before any Redis read.
- `src/Alerts/IssueDispatcher.php::handle` — belt-and-suspenders silencing guard at the top of `handle()`, **before** `cooldown::acquire`. Scoped to `rule === FailureRateDetector::RULE` only — `slow_p95` also sets `jobClass` but stays unfiltered (silencing is failure-noise, not perf).
- `src/QueueInsights.php::hourlyThroughput` — silenced classes filtered out of the failed-bucket fan-out only; processed bucket stays exact.
- `src/QueueInsights.php::applyFailedJobFilters` — calls `SilencedJobs::appendExclusion` when `includeSilenced` is false. Routes through the same builder as `recentFailed` and `FailedJobUuidCollector` (bulk-retry) so they inherit the exclusion.
- `src/Support/FailedJobFilters.php::$includeSilenced` — DTO toggle. **Default false** treated as "no filter" by `isEmpty()` so the bulk-retry footgun guard still rejects empty-filter retries.
- `src/Dashboard/ClassRowsBuilder.php` — emits `silenced => bool` per row; the view renders a muted badge.
- `src/Http/Livewire/QueueInsightsDashboard.php::$includeSilenced` — `#[Url(as: 'fs')]`. Reset in `clearFailedFilters`. `updated()` resets `failedPage` on toggle.
- `resources/views/partials/filter-form.blade.php` — optional `$silenceModel` arg gates the "Show silenced" checkbox; `pane-failed.blade.php` passes `'silenceModel' => 'includeSilenced'`, `pane-completed.blade.php` doesn't (the form is shared).

What NOT to do (silenced-jobs specific):

- Don't filter modal-by-uuid / batch-detail / chain-lineage click-through paths. Silencing is a list-level filter — once the operator has the uuid in their hand (deep-link, batch item, chain parent), the modal must always open.
- Don't make `slow_p95` honour silencing without a separate config knob. The current design keeps "failure noise" and "performance noise" orthogonal so operators don't accidentally mute a class's latency alerts when silencing flake.
- Don't add a writer-side filter "for performance". The aggregate counter cost is a single INCR per event; a silenced-aware listener path would couple read-side config to write-side keys and break the reversibility guarantee.

# Backward chain lineage — internals + edit points

AI-facing reference for the `chain_lineage` subsystem. End-user docs live in
`README.md` (the `### Chained jobs` section); the design rationale lives in
`internal/specs/backward-chain-lineage.md`. Read this **before** touching any
of the listeners or support classes listed below.

## What it does

Surfaces **who dispatched this job** for any link in a `Bus::chain([...])`.
The forward direction (where the chain is going) was already captured per-row;
this subsystem captures the backward direction so the failed-job markdown
export and the modal can answer "which job ran *before* this failure" without
inspecting application code.

## Pipeline (do NOT reorder these listeners)

```
JobProcessing(parent) ──► push qi:chain-claim:{conn}:{queue}:{nextClass}:{fp} (LPUSH)
                          [happens BEFORE $job->fire(), which dispatches the child]
                          [Phase 0 ordering test locks this assumption]

JobQueued(child)      ──► RPOP qi:chain-claim:{conn}:{queue}:{ownClass}:{tailFp}
                          on hit  ► SETEX qi:lineage:{child-uuid} = parent-uuid

JobProcessing(child)  ──► HSET pending:{uuid} parent_uuid (in-flight modal)

JobProcessed(child)   ──► XADD completed-stream entry includes parent_uuid field
                          DEL  qi:lineage:{child-uuid}     (durable copy lives on the row)

JobFailed(child)      ──► (no copy — qi:lineage:{uuid} survives at 7d TTL,
                          RowEnricher::failed reads it directly per page)

JobProcessed/Failed(parent) ──► SETEX qi:class:{uuid} = class
                                (uuid → class index; hydrates parent_uuid → label)
```

## Key catalogue

| Key | Writer | Reader | TTL | Purpose |
|---|---|---|---|---|
| `qi:chain-claim:{conn}:{queue}:{nextClass}:{tailFp}` | `RecordJobProcessing` (LPUSH via Lua) | `RecordJobQueued` (RPOP) | `chain_lineage.claim_ttl_seconds` (60 s) | Per-shape FIFO list of parent UUIDs awaiting attribution |
| `qi:lineage:{child-uuid}` | `RecordJobQueued` on claim hit | `RowEnricher::failed`, `RecordJobProcessing` (copy to pending), `RecordJobProcessed` (copy to stream then `forgetLineage`) | `chain_lineage.lineage_ttl_seconds` (7 d) | Interim child→parent pointer; durable for failed-row reads |
| `qi:class:{uuid}` | `RecordJobProcessed`, `RecordJobFailed` | `ParentClassResolver::resolve(Many)` | `chain_lineage.lineage_ttl_seconds` (7 d) | uuid → class label; used to render `Parent: {uuid} (Class)` |
| `parent_uuid` field on the completed-stream XADD | `RecordJobProcessed` (copies from `qi:lineage:{uuid}`) | `RowEnricher::completed` | stream retention | Hot-path read with no Redis hit |

## Touchpoints — files that own this subsystem

- `src/Listeners/RecordJobProcessing.php`
  - `pushChainClaim()` — write side. Decodes parent payload via
    `SerializedCommandReader::extractChainContext`, builds the key, LPUSHes the
    parent UUID through `LuaScripts::pushChainClaim()` (atomic LPUSH+EXPIRE).
  - `copyLineageToPending()` — child's read side. Reads `qi:lineage:{uuid}`,
    HSETs `parent_uuid` onto the pending hash. Skipped when pending tracking
    is disabled.
- `src/Listeners/RecordJobQueued.php`
  - `resolveChainLineage()` — RPOPs unconditionally on every `JobQueued`.
    Phase 0 finding #3 confirms `chainConnection`/`chainQueue` are stripped
    by `SerializesModels::__serialize`, so they cannot be used as a "chained
    child" gate. Root jobs miss harmlessly. Cross-shape collision is the
    only false-positive surface — bounded by `(connection, queue, class,
    tail-classes)` shape equality.
  - `extractTailClasses()` — fail-closed; first malformed `chained` entry
    returns null and skips the lookup, so the read-side never collides on a
    partially-parsed parent fingerprint.
- `src/Listeners/RecordJobProcessed.php`
  - `resolveParentUuid()` — copies `qi:lineage:{uuid}` into the stream entry
    and forgets the interim hash. Idempotent: stream entry is appended once.
  - `qi:class:{uuid}` SETEX block — writes the parent-class index used by
    `ParentClassResolver`.
- `src/Listeners/RecordJobFailed.php`
  - `qi:class:{uuid}` SETEX block. Deliberately does NOT touch
    `qi:lineage:{uuid}` — the interim hash's 7-day TTL matches failed-row
    retention and `RowEnricher::failed` reads it directly via batched MGET.
- `src/Support/ChainLineageClaim.php` — pure key + fingerprint builder.
  No I/O. `fingerprint([])` is the last-link case (sha1 of `'[]'`).
- `src/Support/ChainLineageStore.php` — Redis wrapper. Uses
  `chain_lineage.redis_connection` (override) or falls back to
  `redis_connection`.
- `src/Support/ParentClassResolver.php` — uuid → class lookup. `resolveMany`
  batches via MGET so paged failed/completed lists hydrate in one round-trip.
- `src/Support/RowEnricher.php`
  - `completed()` — reads `parent_uuid` straight off the stream entry, then
    hydrates `parent_class` via batched `ParentClassResolver::resolveMany`.
  - `failed()` — `lineageMany()` MGETs `qi:lineage:{uuid}` for every row,
    then resolves classes the same way.
- `resources/views/partials/parent-lineage-row.blade.php` — the `↰ From`
  block. Caller passes a unique `copyId` (DOM id is collision-prone across
  modals).
- `resources/views/components/details-modal.blade.php` and
  `resources/views/components/failed-modal.blade.php` — both `@include` the
  partial above the existing `Chain` block. The failed-modal markdown
  export builder gains the `**Parent:** \`uuid\` (class)` line.
- `src/Support/ConfigValidator.php::validateChainLineage` — wired into the
  service provider's boot path. Type-checks the toggle, the redis_connection
  override (when non-null), and both TTLs.
- `src/Support/Lua/PushChainClaim.lua` — atomic LPUSH+EXPIRE. The
  `LuaScripts::pushChainClaim()` accessor caches the file content per process.

## Behavioural rules — DO NOT VIOLATE

1. **The write side runs at `JobProcessing`, NOT `JobProcessed`.** The child's
   `JobQueued` fires synchronously inside the parent's `fire()` window —
   pushing the ticket at `JobProcessed` is too late. Phase 0 ordering test
   locks this; if it ever fails after a Laravel upgrade, the design must
   move (probably to `JobQueueing` on the parent) before this feature can
   ship on that version.
2. **Never overwrite a non-null `parent_uuid` with null on the durable
   record.** The retry path can re-fire `JobQueued` with a payload that
   yields no chain extraction; `resolveChainLineage` returns early in that
   case so the existing `qi:lineage:{uuid}` is preserved.
3. **List semantics, not single-key SETEX.** Two parents with identical
   shape concurrently in flight would otherwise overwrite each other's
   ticket. LPUSH+RPOP bounds the worst case to "FIFO order across
   identical-shape concurrent chains" — still ambiguous but no overwrite.
4. **Encrypted parents are silently no-op.** `extractChainContext` returns
   null for `ShouldBeEncrypted` payloads; both write and read sides skip.
   Document this if a host's chains rely on encryption.
5. **`chainConnection`/`chainQueue` are NOT the lineage signal.** Phase 0
   finding #3: `SerializesModels::__serialize` strips properties whose
   value equals their declared default. For default `Bus::chain()` usage
   both fields are null, both are stripped, both are unusable. The read
   side's gate is "always RPOP".
6. **`qi:class:{uuid}` is best-effort.** The class label drops past
   `lineage_ttl_seconds`. Don't add a fallback that scans the completed
   stream — the side-key is the contract; aged-out parents render as
   "uuid only".

## Config surface

```php
'chain_lineage' => [
    'enabled' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE', true),
    'redis_connection' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE_REDIS'),
    'claim_ttl_seconds' => 60,
    'lineage_ttl_seconds' => 604800,
],
```

When `enabled = false` every entry point in this subsystem short-circuits at
the listener level — zero Redis writes, zero overhead. Verified by the
`feature flag off short-circuits before any cache write` test.

## Non-goals

Click-through to parent modal, cross-worker exact attribution, cycle traversal protection. See `internal/specs/backward-chain-lineage.md` for residuals + Phase 4 follow-ups.

# Dashboard dark mode — internals + edit points

AI-facing reference for the dark-mode subsystem. End-user docs live in
`README.md` (the `### Dark mode` section); the design rationale +
phase-by-phase deviations live in `internal/specs/dashboard-dark-mode.md`.
Read this **before** touching any of the files listed below.

## What it does

Tri-state theme toggle (`light` / `dark` / `system`) in the dashboard
header. The header itself stays Horizon-dark in both modes by design;
the rest of the chrome (main bg, hero, tabs, panes, modals, partials)
gains `dark:` Tailwind variants that fire when `<html class="dark">`
is set. Default-on for new installs (post-audit Phase 6 flip). Hosts
disable via `QUEUE_INSIGHTS_DARK_MODE=false`.

## Architecture — single owner, dispatch / listen

```
<head> (survives wire:navigate; runs once per full document load)
   └── FOIT inline script
         ├── reads localStorage['qi-theme'] → falls back to 'system'
         ├── writes documentElement.dataset.theme  (preference)
         ├── writes documentElement.classList.toggle('dark')  (effective)
         ├── matchMedia('(prefers-color-scheme: dark)').addEventListener
         │     └── re-applies when in 'system' mode and OS pref changes
         ├── window.addEventListener('qi-theme-change')
         │     └── localStorage write + apply()
         └── apply() → window.dispatchEvent('qi-theme-applied',
                         { detail: { preference, resolved } })

<body> (morphed by wire:navigate)
   └── theme-toggle component (re-instantiated each morph)
         ├── x-data reads documentElement.dataset.theme on init
         ├── x-on:qi-theme-applied.window → mirrors $event.detail.preference
         ├── role="radiogroup" + per-button role="radio" + aria-checked
         ├── roving tabindex (only checked radio in Tab order)
         ├── arrow-key handlers (Left/Right/Up/Down) cycle via moveBy(±1)
         └── click → window.dispatchEvent('qi-theme-change', { detail })
```

The head script is the **single owner** of `localStorage`, `matchMedia`,
`documentElement.dataset.theme`, and `documentElement.classList`. The
toggle never writes any of those directly — it dispatches a custom event
and lets the head script handle the side effects. This makes
`wire:navigate` lifecycle clean: the toggle's Alpine instance can be
torn down and re-instantiated freely; the head script's listeners
survive at document level and never duplicate.

## Token mapping (from §3.1 / §3.2 of the spec)

The dark-mode audit used a fixed token map — any new view file should
follow the same pairings or update the spec.

| Light | Dark |
|---|---|
| `bg-gray-50` (body) | `dark:bg-gray-950` |
| `bg-white` (cards, modals) | `dark:bg-gray-900` |
| `bg-gray-100` (subtle fill) | `dark:bg-gray-800` |
| `bg-gray-950/5` (tonal fill) | `dark:bg-white/10` |
| `text-gray-900` | `dark:text-gray-100` |
| `text-gray-700` | `dark:text-gray-300` |
| `text-gray-500` (content muted) | `dark:text-gray-300` (bumped for AA) |
| `text-gray-400` (decorative) | `dark:text-gray-400` (held) |
| `text-gray-300` (very muted) | `dark:text-gray-500` |
| `border-gray-950/5` | `dark:border-white/10` |
| `ring-gray-950/5` / `ring-gray-950/10` | `dark:ring-white/10` |
| `divide-gray-950/5` | `dark:divide-white/10` |
| `text-X-700` (semantic) | `dark:text-X-300` |
| `text-X-900` (bold semantic) | `dark:text-X-200` |
| `bg-X-50` (semantic tint) | `dark:bg-X-900/40` |
| `bg-X-100` (semantic chip) | `dark:bg-X-900/60` |
| `ring-X-600/20` (semantic ring) | `dark:ring-X-400/30` |
| `border-X-200/60` (sub-table rule) | `dark:border-X-400/20` |
| `divide-X-200/60` (sub-table divider) | `dark:divide-X-400/20` |

**Always-dark surfaces** (no `dark:` variants applied):
- `<header class="bg-gray-900">` in `layouts/app.blade.php` — brand chrome.
- `qi-time` tooltip — uses `gray-700` in dark, `gray-900` in light (CSS rule).
- The throughput-sparkline tooltip.

## Touchpoints — files that own this subsystem

| File | Role |
|---|---|
| `config/queue-insights.php` | `dashboard.theme.enabled` env-backed flag. |
| `src/Support/ConfigValidator.php::validateDashboard` | Type-check the flag at boot. |
| `resources/views/layouts/app.blade.php` | FOIT head script, color-scheme meta, `tailwind.config` block, body dark classes, inline-style html.dark overrides, JSON-colorizer dual-class spans, inline theme-toggle render. |
| `resources/views/components/theme-toggle.blade.php` | Tri-state segmented pill, `role="radiogroup"` + roving tabindex + arrow-key cycling, dispatches `qi-theme-change`, listens for `qi-theme-applied`. |
| `tests/Feature/View/DarkModeRegressionGuardTest.php` | File-level pair-check guard. Scans every blade in `resources/views/` (extracting only class-attribute contexts to skip comments/JS strings) and fails when a light surface token has no paired dark companion. |

## Behavioural rules — DO NOT VIOLATE

1. **The head script is the single owner of `matchMedia` and
   `localStorage`.** The toggle never subscribes directly. Two
   listeners would leak under `wire:navigate` because the toggle's
   Alpine instance is destroyed/re-instantiated per morph but the
   head script's listener lives at document level. One owner, one
   subscription.
2. **`tailwind.config = { darkMode: 'class' }` is unconditional.**
   Even when the master flag is off. Without it, Tailwind v3
   defaults to `media` mode, which means `dark:` variants would
   auto-fire on `prefers-color-scheme: dark` regardless of the
   flag — exposing half-themed surfaces to system-dark hosts during
   any future incremental audit. Layout's gating is on the head
   script + meta + body classes only.
3. **`html.dark`-scoped CSS is unconditional.** The two CSS rules
   for `[data-qi-copy][data-qi-copied]` (copy-button feedback) and
   `#qi-time-tooltip` (tooltip surface) are emitted always. They
   only match when `.dark` is present on `<html>`, so emitting
   them with the flag off is free.
4. **Custom event names are kebab-case (`qi-theme-change` /
   `qi-theme-applied`).** Alpine's `x-on:` directive parses
   colon-in-event-name unreliably across versions. Renaming was
   the cleanest fix.
5. **The toggle is rendered inline in the layout's header, NOT
   pushed via `@push('header-scope')`.** Layout-side `@push`
   directives fire BEFORE `{{ $slot }}` renders the dashboard's
   own pushes — the toggle would land to the LEFT of the
   connection-scope picker (wrong order). Inline placement skips
   the stack mechanism and lets the toggle sit alongside the
   polling chip via a shared `ml-auto` flex wrapper.
6. **Default flag is `env(QUEUE_INSIGHTS_DARK_MODE, true)`.**
   Hosts upgrading get the toggle automatically. The audit phases
   shipped surfaces incrementally with the flag off; flipping it
   is the "ship-it" switch. Opt-out path is the env var, not a
   code change.
7. **State separation: preference vs effective.**
   `documentElement.dataset.theme` holds the preference
   (`light` / `dark` / `system`); `documentElement.classList`
   holds the resolved theme (`dark` class present / absent). In
   `system` mode the two intentionally diverge. `qi-theme-applied`
   detail carries `{ preference, resolved }` so listeners can
   bind to either without re-querying `html.dark`.

## What NOT to do

- Don't add `prefers-contrast` or forced-colors handling. Out of
  scope; revisit if a host requests it.
- Don't expose a runtime config-mutation surface (admin UI to
  change theme palettes). Out of scope. Hosts who want different
  shades publish the layout view and patch it.
- Don't switch to `darkMode: 'media'`. The class-based strategy
  is what makes the kill-switch flag work and the toggle's
  forced overrides function. `media` mode would make
  `'dark'`/`'light'` selections impossible.
- Don't extend the regression-guard test to per-element
  granularity without adding a parser (currently a regex pass
  over class-attribute extracts). File-level catches the gross
  "added a new card without dark variants" regression; per-element
  drift is what visual review is for.
- Don't rename `qi-theme-change` / `qi-theme-applied` without
  updating the head script, the toggle component, and the
  Phase 1 / Phase 6 string-grep tests in lockstep.

## Config surface

```php
'dashboard' => [
    // ...
    'theme' => [
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
    ],
],
```

When `enabled = false`: the FOIT head script + `<meta name="color-scheme">`
+ body dark classes + theme-toggle component all skip emission. Tailwind
config (with `darkMode: 'class'` + safelist), `html.dark`-scoped CSS, and
`dark:` variants on individual surfaces ship anyway — they're inert because
no `.dark` class ever lands on `<html>`.

# Prometheus — internals + extension points

AI-facing reference for the Prometheus exposition subsystem. End-user
docs live in `README.md` (the `### Prometheus` section); the design
rationale + open questions live in `internal/specs/prometheus-export.md`.
Read this **before** touching any of the files listed below.

## What it does

Exposes queue-insights state on a scrapeable `/metrics` endpoint
(Prometheus 0.0.4 + OpenMetrics 1.0.0 via `Accept` negotiation), plus
an opt-in `queue-insights:prometheus-push` artisan command for short-
lived processes. Mostly a translation layer over Redis state the
package already owns; the listeners ship 4 new monotonic INCR keys
(`processed-total:*`, `failed-total:*`, plus the per-connection
variants) and the snapshot command ships 1 new monotonic INCR key
(`snapshot-errors-total:*`).

## Touchpoints — files that own this subsystem

- `src/Prometheus/Registry.php` — collect-render orchestrator. Bound
  with `bind()` (NOT `singleton`) per spec §8 — Octane-safety mirrors
  `ActiveIssuesProvider`. Per-flavour memoise (`text` / `openmetrics`)
  + Redis cache `prom:cache:rendered:{flavour}` with TTL =
  `cache_ttl_seconds`. Times the inner collect cycle and feeds duration
  to `Collectors\ExporterSelfCollector::record`.
- `src/Prometheus/Renderer.php` — text-format + OpenMetrics renderer.
  Sample order = registration order; labels alphabetised; `# EOF`
  appended for OpenMetrics only. Rejects invalid metric names at
  render time so a typo'd collector fails loudly.
- `src/Prometheus/Exposition/{Sample,MetricFamily,EscapeLabel}.php` —
  pure DTOs + the label-value escape helper.
- `src/Prometheus/Collector.php` — `isEnabled() + collect(): list<MetricFamily>`.
  Returns a list (deviation from spec §6 example) so
  `DurationAggregateCollector` can emit count/sum/max from one HMGET.
- `src/Prometheus/Collectors/PerClassMonotonicCounterCollector.php` —
  abstract base shared by `JobsProcessedCollector` and
  `JobsFailedCollector`. Subclasses declare `keyShape()`,
  `metricName()`, `helpText()`, `metricToggleKey()`; the base owns
  the MGET-chunk loop.
- `src/Prometheus/Collectors/*Collector.php` — one file per metric
  family (or the trio in DurationAggregateCollector's case). Per-metric
  `isEnabled()` reads `prometheus.metrics.{key}`.
- `src/Support/SnapshotPairs.php` — single source of truth for
  iterating `(connection, canonicalQueue)` pairs from the `snapshots`
  config. All queue-scoped collectors go through it.
- `src/Support/ZsetHead.php` — driver-agnostic decoder for
  `WITHSCORES`-flagged `ZRANGE` heads. Shared with
  `Alerts\Detectors\StuckInFlightDetector` and `OldestPendingDetector`
  so the `member => score` shape can't drift across drivers.
- `src/Prometheus/ClassFilter.php` — `MODE_ALLOW_ALL` /
  `MODE_ALLOW_LIST` / `MODE_TOP_N_BY_RECENCY`. Bound `scoped` so the
  per-(mode, connection) memoise survives one request — three
  class-scoped collectors share a single `ZRANGE` per scrape.
  `allow_list` `array_unique`s the configured FQCNs to rule out
  duplicate Prometheus series on a typo'd config.
- `src/Prometheus/MetricsController.php` — single-action controller.
  `Accept` header drives flavour selection.
- `src/Prometheus/PrometheusAuthMiddleware.php` — fail-closed default:
  bearer token (`hash_equals`) → IP CIDR (`IpUtils::checkIp`) → 403.
- `src/Prometheus/PushGateway/Pusher.php` — Pushgateway client. Builds
  `/metrics/job/{job}/instance/{instance}` URLs with `rawurlencode`,
  forwards basic-auth credentials embedded in the URL.
- `src/Console/QueueInsightsPrometheusPushCommand.php` — one-shot
  collect + push. Fail-closed on missing `pushgateway.instance`
  unless `--accept-shared-grouping` is passed (see §Behavioural
  rules). Catches `InvalidArgumentException` separately from
  `Throwable` so config errors exit `INVALID` (2) and HTTP failures
  exit `FAILURE` (1).
- `routes/prometheus.php` — loaded **independently** of
  `routes/web.php` so headless replicas (dashboard.enabled = false)
  can still expose `/metrics`. `prometheus.middleware = null` falls
  back to the package gate; an explicit `[]` exposes `/metrics` raw
  for hosts behind outer infra auth. Route name `queue-insights.metrics`.
- `src/Support/PrometheusConfigValidator.php` — extracted from
  `ConfigValidator` (mirrors the `validateAlerts → AlertsConfigValidator`
  pattern) to keep cognitive complexity under PHPStan's ceiling.
  Wired in the provider's boot path. Rejects malformed CIDR allow_ips,
  unknown `class_filter.mode` (driven by `ClassFilter::MODE_*`
  constants — single source), non-positive `top_n`, negative
  `cache_ttl_seconds`.
- `src/Listeners/RecordJobProcessed.php::writeProcessedMonotonic` —
  two `INCR` writes (aggregate + per-connection) via
  `KeyPrefix::classKey('processed-total', ...)`, each followed by a
  refreshing 30-day `EXPIRE`. TTL-on-write replaces the prior
  prune-DEL pattern (race-free).
- `src/Listeners/RecordJobFailed.php::writeFailedMonotonic` — symmetric.
- `src/Console/QueueInsightsSnapshotCommand.php::recordError` —
  extends the existing 10-min boolean write with an
  `INCR snapshot-errors-total:{c}:{q}` (no TTL).
- `src/Console/QueueInsightsSnapshotCommand.php::pruneClasses` —
  ZREMRANGEBYSCORE-only on the `classes` / `classes:{conn}` zsets.
  Does NOT touch `processed-total:*` / `failed-total:*`; those age
  out via the listener-side EXPIRE.

## Key catalogue (NEW keys this subsystem owns)

| Key | Writer | Reader | TTL |
|---|---|---|---|
| `processed-total:{class}` | `RecordJobProcessed::writeProcessedMonotonic` | unused by collectors (kept for symmetry + future global rollups) | 30 d, refreshed per INCR |
| `processed-total:{class}:{connection}` | same | `JobsProcessedCollector` (MGET via base class) | 30 d, refreshed per INCR |
| `failed-total:{class}` | `RecordJobFailed::writeFailedMonotonic` | unused (parity) | 30 d, refreshed per INCR |
| `failed-total:{class}:{connection}` | same | `JobsFailedCollector` (MGET via base class) | 30 d, refreshed per INCR |
| `snapshot-errors-total:{c}:{q}` | `QueueInsightsSnapshotCommand::recordError` | `SnapshotErrorsCollector` (GET) | none (snapshot-pair count is bounded by `snapshots` config) |
| `prom:cache:rendered:{flavour}` | `Registry::writeCache` | `Registry::readCache` | `prometheus.cache_ttl_seconds` (5 s default) |

Reused, NOT re-written: `live:depth:*`, `inflight-zset:*`,
`pending-zset:*`, `duration:{class}:{connection}` hash,
`classes:{connection}` zset.

## Behavioural rules — DO NOT VIOLATE

1. **`Registry` is `bind()`, never `singleton()`.** Per-flavour render
   memoise leaks across requests under Octane otherwise. Mirrors
   `ActiveIssuesProvider`. The Redis cache is the cross-request bound.
2. **`ExporterSelfCollector` is `scoped()`.** Registry instances within
   a request share it so the duration sample survives the gap between
   `Registry::collect`'s timing block and the self-collector's own
   `collect()` call. Cross-request leakage is harmless — the gauge is
   "previous cycle" by spec.
3. **`ClassFilter` is `scoped()`.** Per-(mode, connection) memoise
   shares the underlying ZRANGE across the three class-scoped
   collectors in one scrape. Each request gets a fresh memoise.
4. **Collector errors do NOT poison the scrape.** `Registry::collect`
   wraps each collector in try/catch + log warning. One broken
   collector skips its families; the rest render.
5. **`PrometheusAuthMiddleware` is fail-closed.** No silent open
   default. Hosts that override `prometheus.middleware` with an
   explicit array (including `[]`) opt out of this entirely and own
   their own gate.
6. **Snapshot age omits the metric when the key is absent.** Don't
   clamp to 0 — that makes a dead snapshot loop look fresh, which is
   exactly the failure mode the metric exists to catch. Pair with
   `snapshot_alive` for boolean liveness.
7. **No histograms in v1.** The `duration:samples:{class}:{connection}`
   capped LIST cannot back a Prometheus histogram (rotates non-
   monotonically). Hosts who need p95/p99 in Prometheus run their own
   client-side histogram listener or read p95 off the dashboard.
8. **Monotonic counters age out via per-INCR EXPIRE, NOT via the
   snapshot prune.** Earlier drafts pruned `processed-total:*` /
   `failed-total:*` alongside the `classes` zset eviction — that path
   raced with concurrent listener INCRs and could DEL an active class's
   counter mid-tick, breaking Prometheus monotonicity. The listener
   writes refresh a 30-day EXPIRE on every INCR; dormant classes age
   out without any prune logic.
9. **Push command fails-closed on missing `pushgateway.instance`.**
   Operators MUST acknowledge shared-grouping risk via
   `--accept-shared-grouping` or set `instance` per-replica. Silent
   overwrites between clustered pushers are a debugging nightmare.
10. **`class_filter` defaults to `allow_list` with empty list.** Per-
    class metrics are off-by-default — adoption is opt-in. Hosts who
    want all classes set `mode = allow_all` and accept the cardinality
    bill. `allow_list` deduplicates configured FQCNs so a typo'd
    duplicate entry can't produce duplicate Prometheus series.
11. **Use `KeyPrefix::classKey($shape, $class, $connection?)` for
    every monotonic-counter key.** Listener writers and collector
    readers go through the same helper so the key shape can't drift
    on either side. Same guarantee documented on the helper itself.

## What NOT to do

- Don't add a host-side fluent API (`Prometheus::addGauge(...)`)
  modelled on `spatie/laravel-prometheus`. Domain is fixed (queue
  observability); custom metrics belong in the host's own exporter.
  Spec §7 line 243.
- Don't pull in `promphp/prometheus_client_php`. The 80% of its API
  we don't use would still be a public-API dep. The ~150 LOC renderer
  is the cost. If a future phase ships a true histogram, revisit
  then for its battle-tested bucketing.
- Don't write `prom:*` keys outside `KeyPrefix::make()`. Every
  exporter-owned key goes through the configured prefix, same as
  every other package surface.
- Don't add per-class metrics for `slow_p95` (or any other percentile)
  from the sample LIST. The LIST rotates; resolved Q3 in the spec.
- Don't expose the renderer or DTOs as host-extension points. Marked
  `@internal` — collectors are the supported extension boundary.
  Custom collectors implement `Prometheus\Collector` (or extend
  `PerClassMonotonicCounterCollector` if they share the per-class
  MGET shape).
- Don't add `prometheus.metrics.exporter_self` toggle for the
  self-collector — operational value is highest when always-on.
  Adding it later if a host actually asks is cheap.
- Don't reach for a separate `prune-prom-counters` artisan command.
  TTL-on-write in the listener is the only sweep mechanism this
  subsystem needs.

## Config surface

```php
'prometheus' => [
    'enabled' => env('QUEUE_INSIGHTS_PROMETHEUS_ENABLED', false),
    'path' => 'metrics',
    'middleware' => null,                              // null → use package default; [] → expose raw
    'token' => env('QUEUE_INSIGHTS_PROMETHEUS_TOKEN'),
    'allow_ips' => [],                                 // CIDR strings
    'class_filter' => [
        'mode' => 'allow_list',                        // | allow_all | top_n_by_recency
        'classes' => [],
        'top_n' => 50,
    ],
    'metrics' => [ /* per-family on/off toggles */ ],
    'cache_ttl_seconds' => 5,                          // 0 disables both layers
    'pushgateway' => [
        'url' => env('QUEUE_INSIGHTS_PUSHGATEWAY_URL'),
        'job' => env('QUEUE_INSIGHTS_PUSHGATEWAY_JOB', 'laravel-queue-insights'),
        'instance' => env('QUEUE_INSIGHTS_PUSHGATEWAY_INSTANCE'),
    ],
],
```

When `enabled = false` the route + middleware alias are not
registered; collectors are inert. The listener INCRs (`processed-total:*`
etc.) ship anyway — they're harmless when no scrape happens and
removing the gate would couple the listener hot path to a config flag.

# Release Automation

## CHANGELOG.md is updated automatically — do NOT edit by hand for releases

`CHANGELOG.md` is kept in sync with GitHub releases by `.github/workflows/update-changelog.yml`. When a release is published (not just drafted), the workflow uses `stefanzweifel/changelog-updater-action` to prepend the release body to `CHANGELOG.md` and commits the update back to `main`.

This means:

- **Do not** add changelog entries manually when preparing a release. The release body (drafted in `internal/release-notes-<version>.md` and pasted into the GitHub release) becomes the changelog entry automatically.
- **Do not** include a changelog diff in the release PR — the post-release commit comes from CI.
- If the changelog needs a fix *after* a release, edit `CHANGELOG.md` directly and commit — but this is unusual and only for typos or formatting issues in the auto-generated entry.

## Benchmark table in release body is updated automatically

`.github/workflows/release-benchmark.yml` appends the latest benchmark table between the `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers in the release body after publish. Do not paste benchmark numbers manually into the release body with those markers — write the narrative above and let CI fill in the table.

## Release workflow (summary)

1. Draft release notes in `internal/release-notes-<version>.md`
2. Commit and push code + notes file to `main`
3. Tag and create the GitHub release with the release-notes file as the body
4. CI automatically:
   - Appends the benchmark table to the release body
   - Prepends the release body to `CHANGELOG.md` and commits it back to `main`

No manual `CHANGELOG.md` edits are part of the release PR.

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Required Before Any Completion Claim

1. **Run** the relevant command (in the current message, not from memory)
2. **Read** the full output
3. **Confirm** it supports the claim
4. **Then** state the result with evidence

### During Development (after each change)

| Claim            | Required verification                              |
|------------------|----------------------------------------------------|
| Code style clean | `vendor/bin/pint --dirty --format agent` output    |
| Tests pass       | Related tests pass via `--filter` or specific file |
| Bug fixed        | Previously failing test now passes                 |

### At Completion Only (feature/phase done, before PR)

These are slow checks — only run them once at the very end:

| Claim             | Required verification                                           |
|-------------------|-----------------------------------------------------------------|
| Rector ran clean  | `vendor/bin/rector process` showing 0 changes                   |
| PHPStan clean     | `vendor/bin/phpstan analyse --memory-limit=2G` showing 0 errors |
| Full suite passes | `vendor/bin/pest` output showing 0 failures                     |
| Feature complete  | All above checks pass                                           |

### Always Capture Command Output

Append `|| true` to all verification commands (tests, linting, type checks) so the output is always captured, even on failure. Without it, a non-zero exit code can hide the output, forcing an expensive second run just to read the errors.

```bash
# CORRECT — output always visible
vendor/bin/pest --filter=testName || true
vendor/bin/pint --dirty --format agent || true

# WRONG — output lost on failure, wastes time re-running
vendor/bin/pest --filter=testName
```

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.

# Worker command (`queue-insights:work`) — internals + edit points

AI-facing reference for `php artisan queue-insights:work`. End-user docs live in `README.md` (the `## Running workers` section); the design rationale lives in `internal/specs/queue-insights-work-command.md`. Read this **before** touching any of the files listed below.

## What it does

Reads `queue-insights.snapshots`, groups entries by connection, and spawns one `queue:work` subprocess per connection with a comma-joined `--queue=` priority list. The supervisor owns argv assembly + line-prefixed output + signal forwarding + grace + `SIGKILL` escalation + Bash 128+signum exit code propagation. Restart-on-crash and other liveness concerns belong to the host's process manager (systemd, supervisord, docker).

## Pipeline (do NOT reorder these phases)

```
handle()
  ├── pcntl gate          → refuse on POSIX hosts without pcntl
  ├── buildMap()          → snapshots → (connection => [queues])
  ├── resolveConnectionFilter() → --connection= array + CSV + dedup
  ├── collectForwardedFlags() → value + bool flags forwarded verbatim
  ├── buildProcesses()    → factory.make() per connection
  └── supervise()
        ├── startProcesses()        → Process::start($cb) per child
        ├── installSignalHandlers() → pcntl_async_signals + SIGTERM/INT/QUIT
        └── while (alive children) {
              reapExitedChildren()  → Process::wait + flush + record exit
              if first non-zero → terminateLiveChildren(SIGTERM)
              if grace expired  → escalateKill() (SIGKILL + stderr warning)
              Sleep::usleep(100_000)
            }
        → resolveExitCode(): firstFailure ?? 128+signum ?? SUCCESS
```

## Touchpoints — files that own this subsystem

| File | Role |
|---|---|
| `src/Console/QueueInsightsWorkCommand.php` | The supervisor command. `handle()` is the entry; `supervise()` is the wait loop, broken into `startProcesses` / `installSignalHandlers` / `reapExitedChildren` / `terminateLiveChildren` / `escalateKill` / `resolveExitCode` to stay under PHPStan's 20-cog ceiling. |
| `src/Console/WorkerProcessFactory.php` | Test seam interface. `make(connection, queues, forwardedFlags): Process`. |
| `src/Console/DefaultWorkerProcessFactory.php` | Production impl. Uses `PHP_BINARY` + `base_path('artisan')` + `Process(timeout: null)`. The `timeout: null` is load-bearing — Symfony Process defaults to 60s wall-clock and would kill daemon workers. |
| `src/Console/WorkerOutputStreams.php` + `DefaultWorkerOutputStreams.php` | Test seam for the `STDOUT` / `STDERR` stream resources. Tests rebind to `php://memory` for assertion. PHP's `STDOUT` constants are stream resources, not C `STDOUT_FILENO` ints. |
| `src/Console/WorkerOutputPrefixer.php` | Per-(connection, stream) carry buffer that prefixes complete lines with `[{connection}] ` and flushes the unterminated tail on child exit. The carry buffer is required because `Process::start($cb)` chunks may split a line mid-byte. |
| `src/Support/ConfigValidator.php::validateWork()` | Hard exception on non-positive int `shutdown_grace_seconds`. Wired into the provider's boot path. |
| `config/queue-insights.php` `work` block | `shutdown_grace_seconds = 120`. Strictly greater than max child `--timeout` + driver poll latency. |
| `tests/Fixtures/StubWorker.php` | Env-driven stub child for fan-out + signal tests. Standalone PHP script — no composer autoload, native `sleep()` not `Sleep::sleep()` (rector skip configured). |
| `tests/Fixtures/SupervisorLauncher.php` | Bootstraps a Testbench-backed Laravel app + binds a stub factory + runs the command. Used by the subprocess SIGTERM test. basePath points at `vendor/orchestra/testbench-core/laravel` because the package itself isn't an application. |

## Behavioural rules — DO NOT VIOLATE

1. **Always subprocess, never `Artisan::call('queue:work')` in-process.** `queue:work` installs process-global pcntl handlers, enables `pcntl_async_signals(true)` process-wide, never returns under normal operation, and can `exit()` the parent via `Worker::kill()` on memory exhaustion. In-process is unsafe for a supervisor parent. One process model: parent supervisor + N child subprocesses, always — even for single-connection installs.
2. **`pcntl_async_signals(true)` MUST run before `pcntl_signal()`.** Without async delivery, handlers only fire at `pcntl_signal_dispatch()` points and SIGTERM forwarding lags a poll tick. Both lines in `installSignalHandlers()` are required; one without the other is a correctness gap.
3. **Signal handler must be idempotent.** Repeat SIGTERM ticks during the grace window must not reset `$teardownStartedAt` — otherwise an operator who hits Ctrl-C twice resets the SIGKILL clock and never escalates. Guard on `$signalReceived !== null`.
4. **First non-zero child wins the parent exit code.** Subsequent non-zero exits during teardown are recorded in `$exits[]` and printed via `[%s] worker exited %d` but **do not** override `$firstFailure`. Single source of truth — the failure that triggered teardown.
5. **`shutdown_grace_seconds` > max child `--timeout` + driver poll latency.** SQS long-poll = 20s, redis BLPOP up to 5s. Default 120 covers `--timeout=60` + 20s + headroom. Lower values race child shutdown.
6. **Forward `--name=` verbatim.** Earlier drafts proposed rewriting `--name=foo` → `--name=foo-{connection}`; dropped. `queue:restart` reads a global cache key (`illuminate:queue:restart`) — it does not match on worker name. The rewrite buys nothing and breaks `Worker::popUsing($exactName, ...)` host integrations.
7. **`process->wait()` after `isRunning()` returns false.** Drains remaining pipe bytes through the streaming callback so the prefixer's carry buffer holds the true tail before flush. Without the explicit `wait()`, a fast child can exit with bytes still buffered.
8. **Refuse boot when pcntl is unavailable.** No silent orphan path. POSIX without pcntl + Windows both refuse — the orphan-children-on-parent-death failure mode is exactly what this command exists to prevent.
9. **No structured-log mode.** Forwarded streams are operator-facing. Tools needing structured ingestion listen on `JobProcessed` / `JobFailed` events the package already records.

## What NOT to do

- Don't add an `Artisan::call('queue:work')` fast path for single-connection installs. Resolved in spec §2.2 — see rule #1 above.
- Don't blanket-forward unknown `--*` flags to children. The forwarded set in `VALUE_FLAGS` + `BOOL_FLAGS` is **finite and explicit**. Adding a new flag is a one-line change in the const + one test row in the matrix; the upside is zero surprise behaviour when a future Laravel adds a flag we haven't audited.
- Don't add auto-restart on crash, worker pool sizing, dashboard worker-liveness panel, cross-connection priority, or per-queue flag overrides. All explicitly out of scope per spec §2.7. Operators who want N workers per connection run N units with `--connection=X`.
- Don't rewrite `--name=`. Rule #6 above.
- Don't use `pcntl_fork()` for tests. `proc_open` is the mandated pattern (spec §4.2) — PHPUnit's process model makes in-process pcntl handling unsafe. The supervisor-as-subprocess test in `tests/Feature/QueueInsightsWorkSignalTest.php` is the template.
- Don't use `Illuminate\Support\Sleep` in `tests/Fixtures/StubWorker.php`. That fixture runs as a standalone `php StubWorker.php` invocation without composer autoload. `rector.php` already skips `SleepFuncToSleepStaticCallRector` for that path.
</package-boost-guidelines>
