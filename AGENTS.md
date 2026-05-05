<package-boost-guidelines>
# Package Boost Guidelines

These guidelines replace Laravel Boost's default foundation for
repositories that are **Laravel packages**, not applications. The
framing, tooling, and trade-offs differ — follow this version when
working inside a package codebase.

## Foundational Context

This codebase is a **Laravel package** distributed via Composer, not a
Laravel application. Key consequences:

- There is no `artisan`, no `app/`, no `bootstrap/`, no `routes/`, no
  `.env`, and no database by default. A Testbench-provided Laravel
  application is spun up only at test time.
- The primary artefact is the package's public API (service provider,
  facades, classes) — everything else is scaffolding.
- Downstream apps consume this package. Every public change is a
  user-facing API change governed by semver.
- `composer.json` is the source of truth for supported PHP and
  Laravel versions. Check `require.php` and `require.illuminate/*`
  before using version-specific features.

## Use `vendor/bin/testbench`, not `php artisan`

Running artisan commands directly against the package fails — there is
no host application. Use Testbench's binary:

| Instead of | Use |
|---|---|
| `php artisan test` | The package's configured test runner (`vendor/bin/pest` or `vendor/bin/phpunit`) |
| `php artisan tinker` | `vendor/bin/testbench tinker` |
| `php artisan make:*` | Create files manually under `src/` |
| `php artisan vendor:publish` | `vendor/bin/testbench vendor:publish` |

### Commands that require `laravel/boost`

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

## Source Layout

- `src/` — package source, PSR-4 autoloaded per `composer.json`
- `tests/` — Pest or PHPUnit suite, base case `Orchestra\Testbench\TestCase`
- `config/` — publishable defaults (the file shipped with the package)
- `resources/` — views, translations, Boost skills / guidelines
- `database/migrations`, `database/factories` — only if the package
  ships them
- `workbench/` — developer-only Testbench scaffolding; never shipped

Check sibling files before inventing structure. Do not introduce new
top-level directories without a clear reason.

## Cross-Version Compatibility

Supporting multiple Laravel / PHP majors is routine for packages.
Activate `cross-version-laravel-support` **before** writing the
code; activate `ci-matrix-troubleshooting` **after** a matrix cell
has failed.

## Conventions

- Match existing code style, naming, and structural patterns — check
  sibling files before writing new ones.
- Use descriptive names (`resolvePublishDestination`, not `resolve()`).
- Reuse existing helpers before adding new ones.
- Do not add dependencies without approval; every new `require` is a
  constraint downstream consumers inherit.

## Tests Are the Specification

The package has no running application to click through. Tests are how
behaviour is pinned down.

- Write tests alongside any behavioural change. Feature tests through
  Testbench are preferred over ad-hoc tinker scripts.
- Do not create "verification scripts" when a test can prove the same
  thing.
- Run the project's configured test runner (`vendor/bin/pest` or
  `vendor/bin/phpunit`) before claiming a change is done.

## Public API Discipline

- Every `public`, `protected`, or exported symbol is part of the
  package's surface. Breaking changes require a major version bump.
- Prefer `final` classes and `private`/`@internal` markers for anything
  not intended for extension.
- Keep config keys, published asset paths, and service container
  bindings stable across patch and minor versions.

## Documentation Files

Only create or edit documentation (README, CHANGELOG, docs/) when
explicitly requested or when a behaviour change requires it.

## Replies

Be concise. Focus on what changed and why. Skip restating what the
diff already shows.

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
