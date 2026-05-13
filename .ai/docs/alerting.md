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
