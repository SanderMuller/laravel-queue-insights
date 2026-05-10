# Changelog

All notable changes to `laravel-queue-insights` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

New entries are prepended automatically by `.github/workflows/update-changelog.yml` from the published GitHub release body — do not edit historical entries to add releases.

## 0.14.0 - 2026-05-10

Scheduler alerts now route through the same `QueueAlertNotification` pipeline as queue alerts — one mental model, one set of channels (log / slack / mail), with an optional per-domain channel block for hosts that want scheduler alerts in a different Slack channel.

### Highlights

- **Scheduler alerts → unified pipeline.** `ScheduledTask{Failed,Hung,Missed}` now flow through `IssueDispatcher` and `QueueAlertNotification`. Typed events keep firing alongside, so existing host listeners stay wired.
- **Per-domain channel routing.** New `scheduler.alerts.channels` config block (mirrors `alerts.channels` shape). Populate any channel inside it to route scheduler alerts separately; omit it and single-list installs fall back to `alerts.channels`.
- **Slack + mail deep-links.** Scheduler payloads carry a `Run URL` field linking into the dashboard's per-run modal (`?s_rid={taskKey}:{runId}`); missed runs link to the per-task modal.
- **Master-switch semantics.** Scheduler notifications gate on **both** `alerts.enabled` AND `scheduler.alerts.enabled`, so hosts running with typed events alone pre-upgrade don't suddenly start paging.

### Breaking changes

- Scheduler cooldown key namespace moved from `sched:alert:cooldown:{rule}:{taskKey}` to `alert:cooldown:scheduled_task_{rule}:task:{taskKey}`. The first sweep tick after upgrade may fire one duplicate alert per (task, rule) actively cooling down at the boundary. See [UPGRADING.md#scheduler-alerts-route-through-queuealertnotification](UPGRADING.md#scheduler-alerts-route-through-queuealertnotification) for the one-shot Redis cleanup.

### What's Changed

* feat(scheduler-alerts): unify into QueueAlertNotification + per-domain channels
* refactor(dashboard): extract RetryAction + audit context out of Livewire

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.13.0...0.14.0

## 0.13.0 - 2026-05-09

### Highlights

- **Per-task drilldown modal** — click any task in the **Tasks** card on the Schedule tab. Modal renders the cron expression + flag pills (`runInBackground` / `onOneServer` / `withoutOverlapping` / `evenInMaintenanceMode`), a six-tile 24h grid (runs / failed / hung / skipped / missed / runtime p95), the next-due timestamp computed via `dragonmantank/cron-expression` against the task's own timezone, and a recent-runs table scoped to that task. Closure-only tasks render a "Output capture not supported by Laravel for closure tasks" hint above the stats grid; commands don't see it. URL-bound under `?s_tk=` so deep-links round-trip.
- **Per-run drilldown modal** — click any row in the panel-level **Recent runs** table or in the per-task modal's runs table. Modal carries a status-coloured accent stripe, the host id + started-at + runtime + exit-code grid, the `recovered_from_hung` badge when applicable, a structured exception block (class + message + file:line + trace), an output `<pre>` (suppressed for closure tasks even when `has_output=1`), and a skip-reason explainer covering all five `SkipReasonResolver` outcomes (`mutex` / `one_server` / `maintenance` / `between` / `filter`). A copy-as-Markdown button on the header hands the full context to AI agents or trackers. URL-bound under `?s_rid={taskKey}:{runId}` so deep-links round-trip; aged-out runs render an "Expired" empty state instead of 500ing.
- **Host-distribution bar chart** — the per-task modal walks the last 200 runs and renders a per-host bar (count + percentage). Suppressed for tasks that ran on a single host so single-fleet operators don't see visual noise. Answers the "is `onOneServer` actually distributing fairly?" question Laravel has no built-in tool for.
- **Correlated-jobs section** — every uuid the run dispatched (read from the `qi:sched:run-jobs:{runId}` zset already written by `RecordJobQueued`) is listed under a dedicated header on the run modal. Clicking a uuid emits `qi-open-job-by-uuid`, which the parent `QueueInsightsDashboard` resolves via `UuidResolver::resolve` and opens the matching queue-side modal — completed / failed / pending — through the existing `openByUuid` chain. Silenced filters are not honoured here per the read-side-only silencing rule; once the operator has the uuid, the modal always opens.
- **Two new reader methods** — `ScheduleReader::runDetail($taskKey, $runId)` returns the per-run hash plus correlated-jobs list (null on aged-out runs); `ScheduleReader::runOutput($taskKey, $runId)` separately fetches the captured stdout/stderr blob so paged recent-runs lists never accidentally pull multi-KB payloads. Both are part of the public read API and shape-stable.
- **Scheduler-side Prometheus metrics** — eight new families exposed on `/metrics` when both `scheduler.enabled = true` and the per-family toggle is set: `queue_insights_scheduled_task_runs_total{task,status}` (status: `success` / `failed` / `skipped`), `queue_insights_scheduled_task_runtime_sum_seconds_total{task}`, `queue_insights_scheduled_task_last_run_timestamp{task,status}`, `queue_insights_scheduled_task_hung_total{task}`, `queue_insights_scheduled_task_missed_total{task}`, `queue_insights_scheduled_task_in_flight{task}`, `queue_insights_scheduled_snapshot_age_seconds`, `queue_insights_scheduled_sweeper_age_seconds`. **Default off per family** (mirrors the per-class queue-metrics stance). Per-task cardinality control via `prometheus.task_filter` — `allow_all` (default) or `allow_list` with a whitelist of `taskKey`s; `top_n_by_recency` is intentionally not supported for v1 (would require a new write path). Snapshot + sweeper age gauges omit their sample when the underlying key has never been written so Prometheus alerts can use `absent(...)` cleanly. Backed by a new `runtime_sum_ms` counter on `qi:sched:counters:{taskKey}` that pairs with `total_runs` for a monotonic mean — the existing per-bucket `runtime_sum_ms` on `sched:agg:*` rolls hourly and TTLs out, so cannot back a Prometheus counter on its own.

### Fixes

- **`<x-qi-time>` now auto-detects ms-scale timestamps**. Bare ints `>= 10^12` are divided by 1000 before being handed to `Date::createFromTimestamp`, so the schedule subsystem's `started_at` / `finished_at` / `snapshot:at` fields stop rendering as "56297 years from now". Existing call sites passing unix-seconds are unaffected — no real seconds-timestamp will hit `10^12` for the next 32 millennia. No per-call-site `:ms` flag needed.
- **`scheduler.snapshot_rebuild` flag (default `true`)** gates the `app->booted` callback that rewrites `qi:sched:tasks` + `qi:sched:tasks:order` from `Schedule::events()`. Hosts that pre-seed the snapshot keys themselves — workbench preview seeders, fixture-driven staging environments, custom import scripts — can set `QUEUE_INSIGHTS_SCHEDULER_SNAPSHOT_REBUILD=false` to keep their own data. The workbench preview turns it off internally so Livewire polls no longer collapse the 6-task fixture down to the package's single auto-registered `queue-insights:snapshot` task on every interaction.
- **`Scheduler\CommandLabel::short()`** strips Laravel's `Event::compileCommand()` artefacts in list surfaces — a Herd-emitted `'/Users/foo/Library/Application Support/Herd/bin/php' 'artisan' 'queue-insights:snapshot'` becomes `php artisan queue-insights:snapshot`. Recognises macOS / Homebrew / Linux / Windows binary paths and preserves versioned suffixes (`php8.2`, `php-cli`). Applied to the Tasks card, the Recent runs row, the filter dropdown, and the per-run modal header. The per-task modal still shows the full unmodified command verbatim — operators debugging an unusual interpreter path keep that information.
- **Scheduler alert cooldown semantics clarified.** The README previously claimed `ScheduledTaskFailed` / `Missed` / `Hung` events fire on every detection regardless of cooldown, with cooldown only gating outbound notifications. That was wrong — cooldown gates the **event dispatch itself**, so host listeners only see the leading edge of an alerting condition; subsequent ticks within the cooldown window are silent until cooldown expires. Behaviour unchanged; the docs now match the code.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.12.0...0.13.0

## 0.12.0 - 2026-05-08

**Scheduler observability** — capture, dashboard panel, and missed/hung detection for `Illuminate\Console\Events\Scheduled*`. Off by default; existing hosts opt in via one env var. Plus a one-line breaking change for hosts on SQS — `aws/aws-sdk-php` is no longer a hard `require`.

#### Highlights

- **Scheduled-task capture** — the package now listens on `ScheduledTaskStarting` / `Finished` / `Failed` / `Skipped` / `BackgroundTaskFinished` and records per-task definition snapshots (cron, command summary, `runInBackground`, `withoutOverlapping`, `onOneServer`) plus per-run records (start, finish, exit code, runtime, host, captured output). Output capture is three-mode (`off` / `metadata` / `full`) mirroring the existing job-payload capture knobs and short-circuits the host's bound `PayloadSanitizer` for the `full` path.
- **Dashboard panel** — a new lazy-loaded **Schedule** tab on `/queue-insights` renders headline tiles (runs / failed / skipped / hung / missed / runtime p95 over 24h), an hourly throughput sparkline, a needs-attention vs. healthy task split, and a paginated recent-runs table with task / status / host / date filters. Click any task row to narrow recent runs to that task. Gated by an optional `viewScheduleInsights` ability — falls back to the existing `viewQueueInsights` when the ability isn't defined.
- **Missed + hung detection** — `php artisan queue-insights:schedule:sweep` (run on its own short cron, e.g. `* * * * *`) walks each captured task and flags a run **missed** when the cron expression's next-fire passes without a `Starting` event landing inside `sweeper.drift_seconds` (default 90 s), or **hung** when no `Finished` / `Failed` arrives within `expected_runtime + grace_seconds` (default 300 s). Expected runtime is the rolling p95 from aggregates and falls back to grace alone for tasks with fewer than `hung.min_runs_for_p95` (default 10) recorded runs.
- **Typed events** — `SanderMuller\QueueInsights\Events\ScheduledTaskMissed`, `ScheduledTaskHung`, and `ScheduledTaskFailed` always fire when detected, regardless of cooldown or alert-channel configuration. Cooldown gates **only** outbound notifications via the existing `QueueAlertNotification` plumbing — host listeners can route each event independently.
- **Job-to-schedule attribution** — when scheduler observability and pending tracking are both enabled, jobs dispatched inside a scheduled task's run window are stamped with the active task key + run id on the per-uuid pending hash, so the dashboard can answer "which scheduled task dispatched this job" without an extra config surface.
- **`queue-insights:schedule:list`** — read-only CLI table of every captured task with cron expression, command summary, and 24h counters. Useful for sanity-checking that capture is wired up before mounting the dashboard panel.

#### Breaking changes

- `aws/aws-sdk-php` moved from `require` to `suggest`. Hosts on Redis / database / sync queues drop ~15 MB of unused install footprint; hosts that snapshot at least one SQS connection must add the SDK to their own `composer.json` (`composer require aws/aws-sdk-php:^3.0`) or `queue-insights:snapshot` will fatal with `Class "Aws\Sqs\SqsClient" not found` on the first SQS tick. See [UPGRADING.md](UPGRADING.md) for the migration step and how to tell whether you're affected.

#### Upgrade notes

Scheduler observability is **off by default** — set `QUEUE_INSIGHTS_SCHEDULER_ENABLED=true` (and restart workers + php-fpm so the listener wiring picks up) to start capturing. Output capture defaults to `metadata` (exit code only); flip `QUEUE_INSIGHTS_SCHEDULER_CAPTURE=full` if you want stdout/stderr stored alongside each run, after auditing your `PayloadSanitizer` for the new surface.

Hosts that previously ran `vendor:publish --tag=queue-insights-config` need to merge the new `scheduler` block into their published config — `mergeConfigFrom` is a shallow merge, so the package defaults are silently ignored for the missing key. Copy the block from `config/queue-insights.php` in the package source.

Run the sweeper on its own short cron once capture is enabled, otherwise missed / hung runs go undetected:

```php
// app/Console/Kernel.php
$schedule->command('queue-insights:schedule:sweep')->everyMinute();



```
### What's Changed

* chore(deps-dev): update predis/predis requirement from ^2.2 to ^2.2 || ^3.0 by @dependabot[bot] in https://github.com/SanderMuller/laravel-queue-insights/pull/7

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.11.0...0.12.0

## 0.11.0 - 2026-05-08

Dashboard **dark mode** + a per-page dropdown for the Completed and Failed lists. Both default-on for new and existing installs; no breaking API changes.

### Highlights

- **Tri-state theme toggle** (sun / monitor / moon) in the dashboard header — `light`, `dark`, and `system` (follows `prefers-color-scheme`, default). The header itself stays Horizon-dark in both modes; the rest of the chrome — main background, hero, tabs, panes, modals, partials — flips between light and dark surfaces. WAI-ARIA APG segmented-control: `role="radiogroup"` with roving `tabindex` and arrow-key cycling.
- **No flash of incorrect theme** on first paint — a synchronous inline script in `<head>` resolves the preference (`localStorage['qi-theme']`) before the body renders. Theme survives `wire:navigate` morphs without leaking listeners.
- **Per-page dropdown** on the Completed and Failed tabs — operator-controlled choice between 10, 25, 50, and 100 rows per page, persisted via URL params (`cpp` / `fpp`) so deep-links round-trip the size. Default per-page is now **10** (was 25) to keep tab content above-fold-friendly on a laptop viewport.
- **`Illuminate\Pagination\LengthAwarePaginator`-backed pagination** for both lists. The page-name parameters (`cp` / `fp`) and existing deep-link URLs are unchanged — bookmarks from 0.10 still resolve.
- **Disable dark mode entirely** via `QUEUE_INSIGHTS_DARK_MODE=false` — the head script, color-scheme meta, and toggle component all skip emission and the dashboard reverts to its pre-feature always-light rendering.

### Upgrade notes

Hosts that previously ran `vendor:publish --tag=queue-insights-config` need to merge the new `dashboard.theme` block into their published config — `mergeConfigFrom` is a **shallow** merge, so the package default of `enabled => true` is silently ignored for the missing key. The env-var kill switch can't fire until the key exists. See [UPGRADING.md](UPGRADING.md) for the exact block to add.

Hosts that published the layout view face a similar reconcile: the new layout adds an inline FOIT script + `tailwind.config` block + theme-toggle render that won't reach a frozen published copy. UPGRADING.md walks through the three reconcile paths (re-publish / manual diff merge / keep-as-is).

Operators on system-dark hosts will land in dark mode immediately on upgrade — the default `system` mode follows `prefers-color-scheme`. Refresh runbooks/screenshots that assume the always-light look, or set the env var to opt out.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.10.0...0.11.0

## 0.10.0 - 2026-05-07

`silenced_patterns` glob fallback for the existing silenced list, a new `<x-qi-time>` blade component, and a dashboard layout pass. No breaking changes; existing installs see no behavioural change until `silenced_patterns` is populated.

### Highlights

- New top-level `queue-insights.silenced_patterns` config — `Str::is`-style globs (e.g. `App\Jobs\Reports\*`) silence whole namespaces without enumerating every FQCN. Exact `silenced` matches first; patterns are the fallback path. Same surfaces apply: failed list, headline failed-tile, throughput failed bucket, `failure_rate` detector, dispatcher guard, completed-row filter, silenced-tab roster, SQL `NOT LIKE` exclusion. Counter writes stay unfiltered — removing a pattern still re-surfaces history with no backfill.
- Pattern matching is **case-insensitive** across both lists, mirroring the existing SQL `LOWER()` exclusion path so an operator's casing in config can't desync from the bulk-retry exclusion. `DisplayNamePayloadMatch::patternFromGlob` is the shared builder behind both the include filter and the silenced exclusion.
- `<x-qi-time>` blade component centralises timestamp rendering across modals + lists. Emits a semantic `<time datetime="…">` element so a host can hook a tiny script for browser-local rendering; default output remains the existing UTC-formatted string.
- Dashboard layout pass tightens chrome — `layouts/app.blade.php` overhaul, modals share a component shell, row partials drop redundant attributes. README adds a TOC + screenshot, condenses the Features bullets, and normalises the heading hierarchy.
- ConfigValidator fail-loud on non-array `silenced_patterns`; relaxed class-label regex now allows `*?` for patterns alongside the existing `@:/` synthetic-label characters.

See the **Silencing noisy jobs** subsection in `README.md` for the new config block and matching semantics.

### What's Changed

* feat(silenced): add `silenced_patterns` glob fallback — [c03d623](https://github.com/SanderMuller/laravel-queue-insights/commit/c03d623)
* feat(dashboard): `<x-qi-time>` component + layout refresh — [7f9a558](https://github.com/SanderMuller/laravel-queue-insights/commit/7f9a558)
* chore(rector): apply locally-called-static + newline-after-statement — [1150c92](https://github.com/SanderMuller/laravel-queue-insights/commit/1150c92)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.9.0...0.10.0

## 0.9.0 - 2026-05-06

Opt-in **Prometheus exposition** — a scrapeable `/metrics` endpoint plus a one-shot `queue-insights:prometheus-push` artisan command for short-lived workers. Default-off; existing installs see no behavioural change until `QUEUE_INSIGHTS_PROMETHEUS_ENABLED=true` flips the gate. No breaking API changes.

### Highlights

- New `/metrics` endpoint covering queue depth, in-flight, pending/delayed, oldest-age, monotonic processed/failed counters, duration aggregates, alert state, and snapshot liveness — Prometheus 0.0.4 + OpenMetrics 1.0.0 via `Accept` negotiation.
- Fail-closed auth by default — bearer token (constant-time) or IP CIDR allow-list; no silent open default. Hosts behind outer infra auth opt out with `prometheus.middleware = []`.
- Per-class metrics off by default. `class_filter` modes `allow_all` / `allow_list` (default) / `top_n_by_recency` bound cardinality; explicit FQCN list dedupes to rule out duplicate Prometheus series.
- True monotonic counters (`processed-total:*`, `failed-total:*`) shipped alongside the existing hourly buckets so `rate()` / `increase()` are safe across retention rotation. Refreshing 30-day EXPIRE per INCR ages dormant classes out without a prune sweep.
- `queue-insights:prometheus-push` for short-lived workers — fail-closed on missing `pushgateway.instance` to prevent silent overwrite between clustered pushers; INVALID (2) for config errors, FAILURE (1) for HTTP errors.

See the new **Prometheus** section in `README.md` for the full metric catalogue, configuration block, and scrape-config example.

### What's Changed

* feat(prometheus): scrapeable `/metrics` + push gateway + monotonic counters — [b927a1a](https://github.com/SanderMuller/laravel-queue-insights/commit/b927a1ae1ea9d748acf920797d840d05ab23ad4c)
* fix(prometheus): wrap `mget` / `hmget` args for phpredis splat compatibility — [d937f35](https://github.com/SanderMuller/laravel-queue-insights/commit/d937f35eee8e40e31a32f09bd414e5f78919cc14)
* chore(deps): bump `sandermuller/package-boost` to `^0.11.0` — [e0122da](https://github.com/SanderMuller/laravel-queue-insights/commit/e0122da8a81bfb987249da7f091943073c756629)
* chore(skills): delegate README + release-notes drafting to the new dedicated skills — [96dca8f](https://github.com/SanderMuller/laravel-queue-insights/commit/96dca8f52a9312aa98f3ff99683eec9cc2d406ea)
* chore(tooling): fix pre-release CI-watch deadlock + auto-sync on install — [d893963](https://github.com/SanderMuller/laravel-queue-insights/commit/d8939632a923edad463cc8f13512178935ee0458)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.8.0...0.9.0

## 0.8.0 - 2026-05-05

Multi-connection v2 — scoped dashboards reach parity with the un-scoped view; silenced-jobs read-side filter; Classes tab restored.

### Added

- Per-connection batch indexing. Roster `qi:batches:index:{connection}` populated first-write-wins via `BatchClaimConnection.lua`. Heterogeneous batches land on the dispatching connection; uuid → connection side-key `qi:batch-uuid-conn:{uuid}` keeps scope filter working after pending hashes expire.
  
- Per-connection completed stream `qi:completed:connection:{connection}`. Scoped Recent completed reads it directly — no post-filter starvation under imbalanced traffic. Cap: `retention.per_connection_stream_max` (default 5000, ~10 MB per 10-connection install).
  
- Five Lua scripts that atomically dual-write aggregate + per-connection counters in `RecordJobProcessed::handle` / `RecordJobFailed::handle`:
  
  | Script | Replaces | Guarantee |
  |---|---|---|
  | `IncrPairWithExpire.lua` | `INCR + EXPIREAT` × 2 | Both INCRs land or neither |
  | `DurationPair.lua` | `HINCRBY count + HINCRBYFLOAT sum_ms + max-CAS + EXPIRE` × 2 | Hashes never drift past one event |
  | `SamplesPair.lua` | `RPUSH + LTRIM + EXPIRE` × 2 | Sample lists in lockstep |
  | `SetexPair.lua` | `SETEX` × 2 | `last_run` keys updated together |
  | `ClassesRoster.lua` | `ZADD × 2 + EXPIRE per-conn` | Aggregate + per-connection rosters atomic |
  
- `silenced` config (top-level list of FQCNs). Read-side filter — mirrors Horizon's `horizon.silenced`. Suppresses silenced classes from Failed list, headline failed-tile, throughput sparkline failed series, `failure_rate` detector, and notifications. Counter writes preserved → reversible without backfill. `slow_p95` deliberately not filtered (perf ≠ failure noise). Modal-by-uuid / chain-lineage / batch-detail click-through always resolve.
  
- "Show silenced" toggles on Failed (`?fs=1`) and Completed (`?cs=1`) panes — independent, URL-shareable.
  
- **Silenced** dashboard tab (renders when `silenced` is non-empty). Two-section pane: silenced failed + silenced completed. Constant-cost regardless of list size.
  
- **Classes** tab restored — per-class 24h volume / runtime / p95 / max / last-run table; click row to filter Completed by class. Silenced classes show muted `silenced` badge.
  
- `ConfigValidator::validateRetention` + `validateSilenced` — fail loudly on bad shape. Silenced validator wired outside the `alerts.enabled` gate.
  
- Live demo seeds `App\Jobs\PingThirdPartyVendor` as silenced @ 0.45 failure rate.
  

### Changed

- `retention.processed_counters_days` / `failed_counters_days` now drive bucket EXPIREAT (previously dead config — listeners hardcoded 7 d / 30 d). Defaults unchanged. **Hosts that customised these values will see them applied — review before upgrading.**
- Bulk-retry uuid collector inherits silenced exclusion via shared SQL path; default-filter view never queues silenced classes.
- Aggregate batches roster + un-scoped dashboard read path unchanged → rollback to 0.7.0 is safe.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.7.0...0.8.0

## 0.7.0 - 2026-05-01

Multi-connection scoping — `/queue-insights/{connection}` narrows every panel to the named connection.

### Added

- `/queue-insights/{connection}` route. Scopes queue rows, alerts strip, snapshot watchdog, pending/delayed/in-flight inspectors, recent lists, headline stats, per-class metrics, alert-rules panel. Batches are intentionally hidden under scope in 0.7.x — see Known limitations below; restored in 0.8.0.
- Connection nav strip above headline cards — auto-suppresses for single-connection installs.
- Optional `viewQueueInsightsConnection` gate. When defined: 403s denied scopes, hides them from nav, renames "All" → "All allowed". Without it, scope is reachable to anyone passing `viewQueueInsights` (pre-spec behaviour).
- Per-connection counter dual-writes (`processed:{class}:{connection}:{bucket}`, `failed:{class}:{connection}:{bucket}`, `duration:{class}:{connection}`, `last_run:{class}:{connection}`, `classes:{connection}` zset). Aggregate keys unchanged → rollback safe. Scoped per-class metrics fill in as new events flow.
- `scope_connection` field on `queue-insights.retry` audit log lines.
- `alerts.channels.slack.channel` / `QUEUE_INSIGHTS_SLACK_CHANNEL` — informational label (Slack webhooks bind destination server-side).
- Laravel Cloud demo app under `demo/` with build script, basic-auth gate (`DEMO_*` env), idempotent preview seeder.

### Known limitations under scope (resolved in 0.8.0)

- Batches section hidden under scope (no per-batch connection key in 0.7.x).
- Recent completed reads global stream + filters → can starve scoped rows under imbalanced traffic.
- Per-connection counter dual-write is non-atomic.

### Publishable assets

Re-publish views (`--tag=queue-insights-views`) for `partials/tabs-workspace.blade.php` + `layouts/app.blade.php`.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.6.0...0.7.0

## 0.6.0 - 2026-04-30

Alerting subsystem + backward chain lineage.

### Added — alerting

Enable via `QUEUE_INSIGHTS_ALERTS_ENABLED=true`. Eight detectors run per snapshot tick:

| Rule | Scope | Fires when |
|---|---|---|
| `depth` | per-queue | `live:depth` ≥ configured threshold (highest matching severity wins) |
| `stalled` | per-queue | depth ≥ `min_depth` AND no pickups in `idle_seconds` |
| `oldest_pending` | per-queue | oldest runnable pending job waited `seconds` (skips not-yet-due delayed) |
| `stuck_inflight` | per-queue | longest in-flight job running for `seconds` |
| `failure_rate` | per-class | `failed/(processed+failed) ≥ ratio` over current hour AND total ≥ `min_jobs` |
| `slow_p95` | per-class | per-class p95 ≥ `class_threshold_ms[$class]` (opt-in per class) |
| `snapshot_errored` | per-queue | snapshot driver threw on most recent tick (10-min TTL) |
| `backlog_growing` | per-queue | least-squares depth slope ≥ `min_slope_per_minute` (opt-in, warms after `min_samples`) |

Plus dashboard-only `snapshot_command_dead` watchdog — top banner when `live:depth` keys absent for ≥ 90 s.

- Cooldown is per-(rule, target). Keys: `alert:cooldown:{rule}:{c}:{q}` / `alert:cooldown:{rule}:class:{class}`. Gates outbound notifications only — dashboard always live.
- Three notification channels via `Illuminate\Notifications`: `log` (zero-dep, default on), `slack` (Block Kit, Slack/Mattermost/Rocket.Chat, plain-text fallback), `mail` (subject `[Queue Insights] {severity}: {rule} on {target}`). `slack` + `mail` feature-detect their bindings → `mail`/`guzzle` stay in `composer suggest`.
- Typed events fire regardless of channel config: `QueueDepthExceeded` (gained `?string $severity`), `QueueStalled`, `OldestPendingAging`, `StuckInFlight`, `SnapshotErrored`, `JobClassFailureRateExceeded`, `JobClassP95Exceeded`, `BacklogGrowing`.
- Active-rules panel — read-only summary of `alerts.rules` + `alerts.channels`. `#[Lazy]` Livewire child → -25–30 ms cold first-page.

### Added — backward chain lineage

- `↰ From {parent}` row on completed/failed modals + failed-job markdown export.
- Capture: parent drops a claim ticket into Redis on entering processing keyed `(connection, queue, next-class, tail-fingerprint)`; child's `JobQueued` pops it and stamps `parent_uuid` on lineage hash.
- Click-through to parent modal via `openByUuid` action; parent modal gets `Back to {class}` button.
- Disable: `QUEUE_INSIGHTS_CHAIN_LINEAGE=false`. Encrypted parents (`ShouldBeEncrypted`) silently skipped both sides. Class label best-effort past `chain_lineage.lineage_ttl_seconds` (default 7 d).
- Cross-worker collision tolerance: identical-shape concurrent chains can attribute in dispatch order rather than identity. Within one worker: exact.
- `queue:retry` preserves chained-job lineage — the existing `qi:lineage:{uuid}` is never overwritten with null on re-fire, and the eventual completed-stream entry of a retried chained job still carries the correct `chain` field.

### Changed

- `QueueInsightsServiceProvider` is now a `DeferrableProvider` — singleton/bind chain paid lazily.
- `boot()` reads merged config block once; `validateAlerts` only runs when `alerts.enabled === true`.

### Deprecated

`alerts.thresholds` → `alerts.rules.depth.thresholds`. Backwards-compatible: legacy `alerts.thresholds` still wins (loud deprecation logged on boot) so prod alerts never silently drop. Migrate at convenience:

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
`mergeConfigFrom` is shallow — published config doesn't pick up new nested defaults. Copy keys from the package config when migrating.

### Publishable assets

Re-publish for `partials/alerts-strip.blade.php`, `partials/snapshot-watchdog-banner.blade.php`, `partials/parent-lineage-row.blade.php`, `partials/chain-back-button.blade.php`, `livewire/alert-rules-panel.blade.php`, `livewire/alert-rules-panel-placeholder.blade.php`.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.5.0...0.6.0

## 0.5.0 - 2026-04-28

Tabbed dashboard + server-side pagination + internal decomposition. No public API breaks; no config changes.

### Added

- Six dashboard tabs: Overview (default), Queues, Pending, Batches, Completed, Failed. Active tab persists in `window.location.hash` (`#qi-overview`, …).
- Overview pane: 4-card mission grid (Queues / Pending / Recent completed / Recent failed). Click rows to open the same modals as full tables; "See all N →" footer switches tab.
- Persistent hero (sparkline + 6-KPI panel) sits above tab strip.
- Server-side pagination on Completed (`?cp=`) + Failed (`?fp=`) — 25 rows/page, 10 pages over the most-recent 250-row window. Filter changes auto-reset to page 1. Out-of-range page clamps to last available.
- `<x-queue-insights::meta-pill>` Blade component — replaces 15 hand-coded `<dl>` blocks across modals. Fixes the `bg-gray-950/[0.04]` dt + `bg-white` dd transparent-`<dd>` styling drift.
- `<x-queue-insights::list-row>` Blade component — owns `role="button"` + `tabindex` + keyboard handler scaffold across the four row partials.
- `Support\WaitTimeMetrics::format(?int $ms): string` — public ms-to-human formatter (exposed as `$fmtMs` in view data).

### Changed (internal)

- Livewire dashboard component shrunk 964 → ~510 LOC. `render()` is a one-liner over `Dashboard\DashboardData::build($component)`.
- New `@internal` `Dashboard\` namespace: `DashboardData` (orchestrator; owns `PER_PAGE=25`, `RECENT_FETCH_LIMIT=250`), `ModalResolver`, `HeadlineStatsBuilder`, `FilterOptionsBuilder`, `ClassRowsBuilder`, `QueueRowsBuilder`.
- New `@internal` Support helpers: `QueueAggregates`, `FailedJobUuidCollector`.
- `tabs-workspace.blade.php` (was 507 LOC, 6 inline panes) split into `partials/tabs/pane-*.blade.php` + `tab-button` + `card-mini-row` + `pagination-controls` + `persistent-hero`. `dashboard.blade.php` reduced to a 44-line shell.

### Fixed

- Modal overlays gain `z-50` on `fixed inset-0` wrapper — modals no longer get covered by portaled UI.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.4.1...0.5.0

## 0.4.1 - 2026-04-28

### Fixed

- **Failed-jobs class filter returned 0 results on MySQL.** `addslashes('App\Jobs\Foo')` doubled `\` to `\\`; MySQL's default LIKE escape (`\`) collapsed it back to `\` while the JSON column stored `\\` (json_encode persists `\` as `\\`). Fix: derive needle from `json_encode($filters->class)` + `ESCAPE '|'` (portable across MySQL/PostgreSQL/SQLite). `LOWER()` kept on both sides so deep-linked URLs with mismatched casing still match. User-supplied `%`/`_`/`|` escaped to block wildcard smuggling.
- **Boundary-case test flake on prefer-stable.** `pending vs delayed by available_at <= now` test seeded `d1 = now + 1`; second-rollover between test capturing `$now` and `pendingJobs()` re-reading `Date::now()` flipped buckets on slow runners. Pinned via `Date::setTestNow()`.

### Added

- `<x-queue-insights::nested-data :data="$value">` Blade component — recursive tree renderer for nested-array Other-fields (`illuminate:log:context`, etc.) on completed/failed Raw tab. Uses `<template x-if>` (not `x-show`) so collapsed subtrees don't materialize. Depth-cap 6. Container header summarises (`object · 3 keys` / `array · 12 items`).

Re-publish views for the new `nested-data` component. Class-filter fix is in `src/` only.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.4.0...0.4.1

## 0.4.0 - 2026-04-27

Batches, in-flight, chained-job inspector. Drop-in upgrade from 0.3.x — no schema, no API breaks.

### Added — batches

- Top-level **Batches** section. Row: name (or `Batch <short-id>`), progress bar from `Bus::findBatch()`, counts triplet (`processed/total · failed · pending`), `cancelled` / `finished` chips.
- Batch modal: per-uuid items in enqueue order, status icon (✓ / ✗ / ▶ / ⌛), `← Back to batch`. Expand state URL-shareable as `?batch=<batchId>`.
- Authoritative counts come live from `Bus::findBatch()` per render — package only stores index/uuid-list/reverse-lookup.
- Storage: ~50 bytes/uuid amortised. Per-batch keys TTL via `batches.ttl_seconds` (default 7 d). Index self-prunes via `ZREMRANGEBYSCORE` on enqueue.
- Disable: `QUEUE_INSIGHTS_BATCHES_ENABLED=false`.

### Added — in-flight

- Third sub-group above Pending now / Delayed, longest-running first via dedicated `inflight-zset`.
- Pending → in-flight transition wrapped in `MarkInFlight.lua` so the dashboard never sees a job missing from both groups during handoff.
- In-flight modal variant with `Started` + `Running for` tiles. ~30 bytes per running job, cleared on `JobProcessed`/`JobFailed`.
- Disable shares the `pending:{uuid}` hash: `QUEUE_INSIGHTS_PENDING_ENABLED=false`.

### Added — chained jobs

- `↳ NextJob (+N)` chip on completed/failed list rows; full FQCN + total count on hover.
- `Chain` block in completed/failed modals — clickable, swaps modal into "Chained jobs" detail view listing every link with per-link routing + `← Back` (or `Esc`).
- Failed-modal chain detail also shows constructor properties extracted from persisted serialized payload (framework internals filtered). Completed stays metadata-only.
- Source: `failed_jobs.payload.data.command` for failed; JSON `chain` field on stream entry for completed (independent of `capture.payloads`). Encrypted jobs (`ShouldBeEncrypted`) silently omit chip + section.

### Added — cross-modal navigation

- Item modals stack on top of batch modal (don't unmount). `← Back to batch` returns to batch view.
- Batch chip on every completed/failed/pending row + inside modal heroes.
- `openBatch(string $id)` Livewire action — closes any open item modal in the same round-trip.
- Direct-by-uuid pending hydration + direct batch lookup as fallbacks for items outside top-50 window.

### Fixed

- **`RecordJobFailed` indexed wrong row on retry-then-fail.** `DatabaseUuidFailedJobProvider::log()` inserts a fresh row per `JobFailed`; `where('uuid', $uuid)->value('id')` returned the OLDEST. Now sorts `id desc`.
- **`Batch::progress()` cross-Laravel parity.** Returns float on Laravel 11/12 (PHP `round()` defaults float), int on Laravel 13. Cast to int in `BatchReader::projectBatch()`.

### Public API (additive)

- `QueueInsights::recentBatches`, `batchDetail`, `allInFlightJobs`, `allPendingJobs` (cross-queue, was per-queue), `allDelayedJobs`, `findPendingByUuid`.
- `Support\BatchReader`, `Support\RowEnricher`, `Support\Lua\MarkInFlight.lua`.
- `Support\PendingJobsReader::findByUuid`.
- `Support\SerializedCommandReader::extractChainContext` — now includes per-job `properties` map.
- `QueueInsightsDashboard`: `openBatch`, `closeBatch`, `openPending`, `closePending`, `toggleBatchInspector`. Props: `$expandedBatchId` (`#[Url(as: 'batch')]`), `$selectedPendingUuid`.
- New publishable Blade components: `batch-modal`, `pending-modal`, `hint`. Partials: `batch-row`, `batch-chip`, `pending-row`.
- New config block:

```php
'batches' => [
    'enabled' => env('QUEUE_INSIGHTS_BATCHES_ENABLED', true),
    'max_uuids_per_batch' => 5000,
    'max_per_query' => 100,
    'ttl_seconds' => 604800,
],





```
**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.3.0...0.4.0

## 0.3.0 - 2026-04-26

Pending & delayed-jobs inspector — driver-agnostic via event capture (works on SQS).

### Added

- Per-queue collapsible inspector with two mini-tables: **Pending** (`available_at <= now`) + **Delayed** (`available_at > now`). Each row: class FQCN + humanized timestamp. Expand state URL-shareable as `?qopen=connection:queue`.
- `JobQueued` listener stamps per-uuid hash + per-queue zset on every queued job. `JobProcessing` clears on pending → in-flight; `JobProcessed` + `JobFailed` belt-and-suspenders cleanup.
- All four listeners route queue value through `CanonicalQueueKey` so SQS producers (queue URL) and workers (queue name) write/clean the same key.
- **Tracking-gap drift signal.** When event-derived zset diverges from `Driver::depth() + Driver::delayed()` by more than `pending.gap_warn_threshold` (default 5): `+N gap` badge on toggle + banner inside inspector telling operators the lists are a *sample*, not enumeration. Snapshot count up top remains authoritative.
- Disable: `QUEUE_INSIGHTS_PENDING_ENABLED=false`. Listener writes become no-ops; residual data ages via TTL.

### Storage bounds

- Per-queue cap `pending.max_per_queue` (default 10000) — `ZREMRANGEBYRANK` evicts lowest `available_at` first.
- TTL safety net `pending.ttl_seconds` (default 24 h) for orphans (worker crash, raw `Queue::push()`).
- Worst case: ~5 MB Redis per queue at default cap.

### Public API (additive)

- `QueueInsights::pendingJobs`, `delayedJobs`, `pendingTrackedCount`.
- `Support\PendingJobsReader`, `Support\ConfigValidator::validatePending`.
- `QueueInsightsDashboard::$expandedQueueKey` (`#[Url(as: 'qopen')]`), `toggleQueueInspector`.
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

### Added

- **Laravel 13 support.** `illuminate/console`, `illuminate/contracts`, `illuminate/queue`, `illuminate/redis`, `illuminate/support` accept `^13.0` alongside `^11.0` + `^12.0`. Dev: `orchestra/testbench` accepts `^11.0`; `pestphp/pest` + plugins accept `^4.0` (Pest plugin Laravel v4.1.0 is the first with `laravel/framework: ^13.0`).
- CI matrix: `13.* × testbench 11.* × prefer-lowest|prefer-stable × PHP 8.3|8.4 × predis|phpredis`. Laravel 11 + 12 legs continue.

### Documentation

- README documents the row partials added in 0.2.0 (`partials/queue-row`, `completed-row`, `failed-list-row`, `filter-form`, `stat-tile`) — publishable individually to override row markup without forking `dashboard.blade.php`.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.2.0...0.2.1

## 0.2.0 - 2026-04-26

Dashboard redesign — denser rows, Horizon-inspired headline stats, completed-list filtering.

### Added

- Compact divider lists for Queues / Recent completed / Recent failed (replaces white-on-white card stacks). Shared `partials/*-row.blade.php` keeps spacing and column geometry identical.
  
- Body palette → `bg-gray-50` so panels read as defined cards. Section headings use `text-lg` + monochrome Heroicon prefix.
  
- Column headers on every list (e.g. completed: `Job · Queue · Runtime · Completed`).
  
- Headline stats panel — six tiles beside throughput sparkline as `lg:col-span-2 + 1` grid (no completed/failed pushdown). All values derived from data already loaded:
  
  | Metric | Source |
  |---|---|
  | Jobs / min | `latest_hour.processed / 60` |
  | Jobs past hour | `latest_hour.processed` |
  | Failed past hour | `latest_hour.failed` (red when > 0) |
  | Max throughput | `max(throughput[*].processed)` over 24 h |
  | Max wait p95 | `max(queues[*].wait_p95_ms)` (amber when > 5 s) |
  | Max runtime p95 | `max(classes[*].p95_ms)` from 24 h class roster |
  
- Recent completed filter — five fields, URL-persistent state:
  
  | Field | Query key | Match |
  |---|---|---|
  | Connection | `cc` | Case-insensitive substring |
  | Queue | `cqu` | Case-insensitive substring |
  | Class | `ck` | Exact FQCN — picks per-class Redis stream |
  | From | `cfrom` | `processed_at >= <Y-m-d> 00:00:00` |
  | To | `cto` | `processed_at <= <Y-m-d> 23:59:59` |
  
- Filter dropdowns instead of free-text on Connection / Queue / Class (both completed + failed). Options come from configured snapshots + 24 h class roster. Dates stay as native `<input type="date">`. Shared `partials/filter-form.blade.php` prevents drift.
  
- At-risk queues group — two ringed panels: **Needs attention** (red ring, `error` + `stale`) above **Healthy** (gray ring). Single-row tints retained.
  
- Workbench preview — `vendor/bin/testbench serve` boots a Livewire-mounted seeded dashboard at `/`. `public/index.php` (Herd entry), `workbench/app/Http/Livewire/PreviewDashboard.php`, `workbench/app/Providers/WorkbenchServiceProvider.php`. `testbench.yaml` now tracked.
  
- Chevron on Recent completed rows — click affordance matches Recent failed.
  

### Removed

- Standalone Job classes section. Per-class metrics still feed the Class dropdown, so scoping by class still works.

### Public API (additive)

- `QueueInsightsDashboard` `#[Url]` props: `completedFilterConnection` (`cc`), `completedFilterQueue` (`cqu`), `completedFilterFrom` (`cfrom`), `completedFilterTo` (`cto`). Existing `selectedClass` → `#[Url(as: 'ck')]`.
- `QueueInsightsDashboard::clearCompletedFilters()`.
- `Support\CompletedRowFilter` — immutable value object mirroring `Support\FailedJobFilters`. `apply(array $rows): array`, `isEmpty(): bool`.
- New publishable partials: `queue-row`, `completed-row`, `failed-list-row`, `filter-form`, `stat-tile`.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.1.0...0.2.0

## 0.1.0 - 2026-04-26

First public release of `sandermuller/laravel-queue-insights` — self-hosted, driver-agnostic queue observability for Laravel. Horizon-style dashboard without Redis-queue lock-in.

### Added

- **Live queue gauges** — per-queue depth / in-flight / delayed across SQS, Redis, database. 24 h history per metric. Stale-snapshot indicator + per-queue snapshot-error badge.
  
- **Per-job-class metrics** — last 24 h processed / failed / avg + p95 + max duration / last run. Click-through filter on Recent completed.
  
- **Throughput sparkline** — 24 h hourly rollup of processed + failed across all classes. Alpine hover tooltip (no 500 ms native-`<title>` lag), failed series colour-banded.
  
- **Recent completed + failed lists.** Completed is stream-backed, metadata-only by default; opt-in payload capture (`metadata` or `full`) with pluggable `PayloadSanitizer`. Failed reads `failed_jobs` table. Full-row click opens modal; keyboard-accessible (Enter / Space), focus-visible outlines.
  
- **Structured details modal** — identity hero (FQCN + connection + queue), metrics row, grouped raw-fields panel. JSON tab + Raw fields tab. Decoded `data.command` properties via safe `unserialize(allowed_classes: false)` — recursive Blade renderer with click-to-expand for nested objects. Capture-mode badge surfaces active retention level. Parsed stack-trace component (vendor frames toggleable) on failed modal. Copy buttons (bg flash + check swap) for stream id / UUID / stack trace / Markdown export of failed-job context (dynamic fence length so embedded triple-backticks don't break export).
  
- **Wait-time capture** — `JobQueued` listener decodes `payload.uuid`, stamps `pushed:{uuid}`; `JobProcessing` computes `wait_ms`. Per-queue p50 / p95 (rolling 1000 most-recent samples; renders `—` until 10 samples). Per-job Wait line in modals next to Duration. 7-day clock-skew guard rejects implausible samples.
  
- **Failed-jobs filter** — Livewire filter row (collapsed default) over connection / queue / class FQCN / date range. URL-persistent via `#[Url]`. Class filter is anchored prefix substring on `payload.displayName`, wrapped in `LOWER(...)` for cross-database parity (MySQL / Postgres / SQLite).
  
- **Retry failed jobs** — single retry in failed modal; bulk retry next to Recent failed when filters active. Routes through Laravel's `queue:retry` Artisan (works on all drivers, idempotent). Two-click in-button confirm. Server-enforced safety:
  
  - Distinct `retryFailedJobs` Gate (separate from read-only `viewQueueInsights`).
  - `RateLimiter` 30 retries / minute / user.
  - Bulk **hard-rejects** when filters empty or matching set > 100 rows — no silent truncation.
  - Non-zero `queue:retry` exit codes surface as red banners.
  - Audit log entry per retry with sanitized filter context (control bytes neutralised, length-capped at 80 chars).
  
- **Embeddable** — standalone Livewire + Blade dashboard. No Filament/Nova coupling. Mounts at `/queue-insights` (gated by `viewQueueInsights`) or embeds via `<livewire:queue-insights-dashboard>`. Composer constraint `livewire/livewire: ^3.0 || ^4.0` (Pulse-style dual support).
  

### Compatibility

- PHP 8.3+
- Laravel 11 or 12
- Redis (Predis or phpredis — both exercised in CI)
- livewire/livewire 3 or 4 *(only when using bundled dashboard route — capture + snapshot run without it)*

### Security

- Payload capture **off by default**. `full` mode requires deliberate config + custom sanitizer when jobs carry sensitive data — see `SECURITY.md`.
- Default sanitizer (`KeyRedactingSanitizer`) redacts `password`, `token`, `secret`, `api_*key`, `authorization` at any depth; truncates oversized scalars; preserves PHP-serialized blobs intact (the modal needs the full blob to extract decoded properties).
- Retry write surface requires separate `retryFailedJobs` Gate; without it the dashboard is fully read-only.

### Install

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config





```
Service provider auto-discovers. See [README](https://github.com/SanderMuller/laravel-queue-insights/blob/main/README.md) for snapshot config, gate setup, embedding pattern.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/commits/0.1.0
