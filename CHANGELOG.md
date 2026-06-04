# Changelog

All notable changes to `laravel-queue-insights` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

New entries are prepended automatically by `.github/workflows/update-changelog.yml` from the published GitHub release body — do not edit historical entries to add releases.

## 0.25.0 - 2026-06-04

<!-- verified-sha: e7229f5ede1de02585bb073f69cc355183afc59f -->
### What's changed

#### Added

- **Failure context capture** — when a job or scheduled task fails, the package now records a snapshot of the surrounding context so you can debug without re-running anything. It captures the visible [Laravel `Context`](https://laravel.com/docs/context) facade at failure time (request id, user id, tenant, trace id — whatever your app puts there, including context added *during* execution) plus a small environment snapshot (worker `host`, `pid`, app `env`, and an optional `release`/deploy identifier). The snapshot shows in the failed-job and scheduled-run modals, rides along in the **Copy as Markdown** export so a failure pasted into an AI agent or issue tracker is self-describing, and is carried on both the `JobFailedAlert` and scheduler `ScheduledTaskFailed` events for host listeners. For scheduled tasks the **root-cause inner exception** (deepest `getPrevious()`) is captured discretely so it survives stack-trace truncation. Context values are redacted by key name through the same `capture.redact_keys` vocabulary as payloads before storage (the export is paste-safe), and hidden context is never captured. On by default (`failure_context.enabled`, `QUEUE_INSIGHTS_FAILURE_CONTEXT`); works on any queue driver. Capture breadth, the redaction allowlist, the release resolver, byte caps, and TTL are all configurable under `failure_context.*`.
  
- **Scheduler failure triage signals** — the scheduled-task modal now surfaces **Last success** (relative time) and a **Failing streak: N in a row** badge, backed by a new `consecutive_failures` counter (increments on failure, resets on the next success), so a flapping task is distinguishable from a persistently broken one at a glance.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.24.0...0.25.0

## 0.24.0 - 2026-06-03

<!-- verified-sha: 53aa475817fb20974f3fc4e0836ac07ba200e8d1 -->
### What's changed

#### Added

- New **`job_failed` alert rule** — an opt-in, event-driven alert that fires once on a job's **final** failure (retries exhausted), the same trigger as [spatie/laravel-failed-job-monitor](https://github.com/spatie/laravel-failed-job-monitor), so you no longer need both packages. On top of a bare per-failure ping it rides the existing alerting rails: per-class **cooldown** (one alert per job class per window), **silencing** (`queue-insights.silenced`, including Horizon's `silenced` merge), and the full multi-channel routing (Slack / mail / Sentry / log). Because the only signal is Laravel's `JobFailed` event, it works on **any** queue driver with no Redis snapshot required. Enable with `alerts.rules.job_failed.enabled = true`. A typed `SanderMuller\QueueInsights\Events\JobFailedAlert` event (carrying the job class, connection, queue, uuid, and live exception) is dispatched cooldown-gated and silencing-filtered for host listeners. It complements `failure_rate` rather than replacing it — `job_failed` is "every failure", `failure_rate` is "a class is failing *a lot*". Notifications for this rule are sent **synchronously in the worker**; high-failure-volume apps can set `alerts.rules.job_failed.notify = false` to keep the event firing while skipping the package's synchronous channels and dispatch their own queued notification.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.23.0...0.24.0

## 0.23.0 - 2026-06-02

<!-- verified-sha: ee19b09ac8df405a72d0986902f1dbb269596609 -->
### What's changed

#### Added

- New **`sentry` alert channel**. When enabled, each cooldown-gated alert is captured into your application's existing Sentry project as a grouped event — severity mapped (`critical → error`, `warning → warning`), tagged by rule / connection / queue / job class, and fingerprinted per `[queue-insights, rule, target]` so Sentry collapses repeats into one issue with a rising event count instead of opening a fresh issue every snapshot tick. The full alert context rides along as a `queue-insights` context block. No DSN lives in the package: the channel captures into whatever Sentry hub the host has already initialised — install [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel), set `SENTRY_LARAVEL_DSN`, then flip `alerts.channels.sentry.enabled` (a matching `scheduler.alerts.channels.sentry` block routes scheduler alerts to the same place). The channel only fires when a Sentry client is actually bound — not merely when the SDK is installed — so a half-configured SDK can't silently swallow alerts: the dashboard's alert-rules panel reports the live state (`disabled` / `SDK not installed` / `hub not configured` / `capturing to host hub`), and a scheduler-sentry-only block with no bound client falls back to the queue-side channels rather than dropping the alert. Host apps can reshape the payload by overriding `QueueAlertNotification::toSentry()`.

#### Internal

- Restored PHPStan compatibility with Symfony 8 / Laravel 13.12. The supported range (`illuminate/* ^11||^12||^13`) now resolves Symfony 8 on a fresh install, which tightened several array-shape inferences; aligned the affected PHPDoc shapes (scheduler task-summary, pending-job rows, schedule aggregates, dashboard panel) and scope-ignored one upstream Testbench PHPDoc mismatch (`Application::create()` reads `enables_package_discoveries` but types it `enabled_…`). No runtime behaviour change.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.22.0...0.23.0

## 0.22.0 - 2026-05-23

### What's changed

#### Added

- Dashboard now uses a left sidebar nav (Overview / Jobs / Classes / Schedule / Alert rules) in place of the horizontal section tab strip. Overview stacks the throughput hero, mission-grid summary cards, and the full queues tables; a new Classes summary card on Overview previews the busiest classes by 24h volume. Legacy `#qi-queues` bookmarks land on Overview and scroll to the queues tables. The mobile disclosure menu mirrors the desktop sidebar.

#### Fixed

- Long SQS / Vapor queue names no longer overflow dashboard cards.

#### Internal

- Migrated dev tooling from the `sandermuller/package-boost` monolith to the split `sandermuller/package-boost-laravel` umbrella (boost-core 0.6.0 + package-boost-php 0.7.0) plus `sandermuller/boost-skills` 1.2.0. `CLAUDE.md` / `AGENTS.md` / `.claude/skills/` / `.github/skills/` are now generated artifacts managed by boost-core; source of truth is `.ai/`.
- Workflow path filters widened: `phpstan` now retriggers on `composer.json` / `composer.lock` / `testbench.yaml`; `run-tests` now retriggers on `testbench.yaml` / `workbench/**`. Config-only changes can't sneak past the matrix gates.
- Detail-modal left rail widened from 20rem to 22rem (batch, schedule-run, schedule-task) for consistent column width across the three modals.

**Full changelog:** 0.21.0...0.22.0

## 0.21.0 - 2026-05-21

### Highlights

- **Job initiator tracking.** Every queued job now records where it was dispatched from, along two axes. The coarse **origin** — the HTTP route, artisan command, or scheduled task the work descends from — rides Laravel `Context`, so it propagates into nested dispatches (a job dispatched by another job inherits the root origin). The opt-in **call site** is the exact `file:line` the `dispatch()` ran from, so two code paths that dispatch the same job class stay distinguishable. Both surface as `Origin` and `Dispatched from` rows in the completed-, pending-, and failed-job modals, and in the failed-job markdown export. Configured under a new `initiator` block — `QUEUE_INSIGHTS_INITIATOR=false` disables the feature; call-site capture is off by default (`initiator.capture_call_site`) since it costs a `debug_backtrace()` per dispatch. See [README.md](README.md#job-initiator).
- **Modal UI consistency pass.** The completed-job modal's layout improvements now apply across the failed, pending, in-flight, and delayed modals — a shared job-config hero, a shared payload-tabs partial, and a trimmed Execution panel — so every job modal reads the same.
- **Orphaned pending/in-flight entries reaped each snapshot tick.** The snapshot command now drops pending/in-flight sorted-set members whose per-uuid hash has expired (worker crashed mid-pickup, raw `Queue::push()` outside Laravel's event flow). Depth / in-flight counts and the `oldest_pending` / `stuck_inflight` alert detectors no longer drift on those orphans, and detector descriptions render human-readable wait times.

### What's Changed

* feat(initiator): track job origin + dispatch call site (d314be4)
* fix(initiator): render the normalized call-site value in the failed modal (33ec8d7)
* ui: apply the completed-modal layout across the failed / pending / in-flight / delayed modals (7c83def, c721bda)
* ui(structured-payload): drop attempts from the Execution panel (c0c14cc)
* refactor(modals): extract a shared payload-tabs partial (559a5c7)
* fix(snapshot): reap orphaned pending/in-flight zset members each tick, confirming hash absence first (19f9ed9, 1c96bfa)
* fix(alerts): exclude orphaned pending/in-flight zset members + humanize detector wait output (543231b, b415eca)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.20.0...0.21.0

## 0.20.0 - 2026-05-15

### Highlights

- **Pending & in-flight payload capture (separate budget).** The completed-stream `capture.payloads` setting now has a sibling for pending rows: `pending.capture.payloads` (`off` / `metadata` / `full`) plus `max_payload_bytes` and `include_command_body`. Defaults to `off` and a 4 KB cap (a quarter of the completed-stream cap) because the memory math is structurally different — completed-stream entries are MAXLEN-trimmed (`N × bytes`), but pending hashes fan out as `max_per_queue × queues × TTL`, which on a 10k-row × 10-queue host is ~400 MB at 4 KB/row. `data.command` stays omitted even under `full` until the host explicitly opts in via `QUEUE_INSIGHTS_PENDING_INCLUDE_COMMAND_BODY=true`, and the same `capture.redact_keys` regex list is applied either way. The pending modal now renders Job-config tiles + a payload note in metadata-only capture, so even at the low default capture mode the modal is informative.
- **Opt-in Redis memory headline tile.** New 7th tile beside the existing headline stats: total Redis bytes consumed by the package's keyspace. SCANs every key under `key_prefix`, pipelines `MEMORY USAGE` per key, sums, and caches the result for `dashboard.redis_memory.cache_ttl` seconds (default 60s) so the per-poll cost is paid at most once per minute. Default-off — the SCAN cost scales with this package's keyspace size; measure before enabling on multi-thousand-key hosts.
  ```bash
  # .env
  QUEUE_INSIGHTS_REDIS_MEMORY_TILE=true
  
  
  
  
  
  
  ```
- **Horizon autodiscovery is now runtime-gated, with a "Horizon not running" banner.** `horizon.autodiscover` becomes tri-state (`true` / `false` / `'force'`). Default `true` only autodiscovers when Horizon's service provider is **actually loaded** in the running app — important for Vapor and similar setups where `config/horizon.php` defines supervisors that are never run from this app context (jobs route to SQS, Horizon's provider is excluded). When `'force'` is set without the provider loaded, the dashboard surfaces a top-level red banner so operators don't read empty supervisor rows as a healthy state. See [README.md](README.md#horizon-supervisor-auto-discovery) for the full tri-state matrix.
- **Sharpened alert output across mail / Slack / scheduler channels.** Every detector now produces operator-readable single-line descriptions (multi-line stack traces collapsed); the typed `SnapshotErrored` event payload still keeps the **raw** `error_message` so host listeners forwarding to Sentry / external systems get the full text. Scheduler alerts gained human-readable task labels in their notification subject + body so on-call doesn't have to map task keys back to commands.
- **12h / 24h clock toggle.** Tri-state header control (12h / auto / 24h). `auto` follows the browser locale + OS 24-hour preference (en-US → AM/PM, en-GB → 24h). Persists per operator via `localStorage['qi-clock']`. Disable via `QUEUE_INSIGHTS_CLOCK_TOGGLE=false` if the host wants to force a single format.

### What's Changed

* feat(pending): capture payloads on pending/in-flight rows under a separate budget (c9fbb6a)
* feat(dashboard): opt-in Redis memory usage tile (bda267c)
* feat(horizon): runtime-gated autodiscovery + horizon-not-running banner (8160499)
* feat(alerts): sanitise + sharpen mail/Slack output across remaining detectors (b22222d)
* feat(alerts): human-readable label on scheduler alerts (722f711)
* feat(dashboard): batch-item dead-click fallback + pending modal auto-transition + 12h/24h clock toggle (771d0bf)
* fix(batch-items): cluster-safe enrichment + pre-decoded chain (81ed61b)
* fix(batch-items): fail-soft on corrupt completed pointers (1dfbedf)
* fix(ValueParser): strict round-trip equality on decodeScalar so trailing garbage rejects instead of laundering (a88e372)
* Two-column pending + schedule modals; details-modal hero absorbs job config + tags (7a47d09, ffd6bf6, 45aa820)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.19.0...0.20.0

## 0.19.0 - 2026-05-14

### Highlights

- **Redis Cluster support (opt-in).** Queue Insights issues multi-key Lua scripts, pipelines, and `MGET` fan-outs that cluster-mode Redis rejects with `CROSSSLOT` when keys span hash slots — so on a cluster those writes silently failed. Set `QUEUE_INSIGHTS_REDIS_CLUSTER=true` and the package wraps `key_prefix` in a Redis hash tag (`{qm:env:}…`), pinning the whole keyspace to one slot so every multi-key op stays legal. `RedisPipeline` routes cluster connections through an eager command fallback (`RedisCluster` has no `pipeline()`). The matching Redis connection must be configured as a real Laravel `clusters` connection so the client follows `MOVED` — see [UPGRADING.md](UPGRADING.md#upgrading-from-018-to-019) for the copy-paste config. A `grokzen/redis-cluster` CI lane exercises the multi-key surfaces against a real cluster on both predis and phpredis. Note: predis's cluster client rejects `RENAME`, so `queue-insights:purge-pending --force` fails closed on predis cluster (it works on phpredis cluster); listeners, dashboard, and alerts work on both.
- **Calmer dashboard typography.** The dashboard now loads the Inter variable font and uses a larger, more consistent text scale across the chrome, queue/job rows, tab strip, and filter toolbar — less visual noise, easier to scan at a glance.

### What's Changed

* feat(redis): Redis Cluster support via single-slot hash-tag pinning (d60c85b)
* dashboard typography pass — Inter variable font + larger text scale (7c1fb53)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.18.0...0.19.0

## 0.18.0 - 2026-05-13

### Highlights

- **`connection_drift` alert rule (opt-in, default off).** Ninth detector. Walks `config('queue.connections')` against the configured canonical queues and flags pending rows under a connection that isn't the canonical for that queue — i.e. dispatcher/worker drift that `connection_aliases` would fix. Pipelined into one Redis round-trip per dashboard tick. Multi-canonical queues (one queue name served by more than one canonical, e.g. `redis-staging:default` AND `sqs:default`) emit a single Issue listing every candidate so the operator picks the right alias target. Cooldown deduplicates per (rule, non-canonical, queue). Enable via:
  ```php
  // config/queue-insights.php
  'alerts' => [
      'rules' => [
          'connection_drift' => ['enabled' => true, 'severity' => 'warning'],
      ],
  ],
  
  
  
  
  
  
  
  
  ```
- **`php artisan queue-insights:migrate-aliases` command.** One-shot migration for hosts that published `connection_aliases` and don't want to wait for `pending.ttl_seconds` (default 24h) to drain the orphan pending zsets. Walks every `pending-zset:{from}:*` + `inflight-zset:{from}:*` per non-identity alias, ZRANGE WITHSCORES → ZADD NX (preserves timestamp scores) → DEL source, then rewrites `pending:{uuid}.connection` from `{from}` → `{to}`. Default dry-run; `--force` to actually mutate. **NOT online-safe** — requires operator-quiesced dispatch + drained workers. The dry-run path prints the quiescence runbook.
- **`connection_aliases` validator rejects Redis glob metacharacters.** `*`, `?`, `[`, `]`, `\` in alias keys or values now fail at boot rather than letting the migration command issue a `KEYS pending-zset:{from}:*` pattern that could match unrelated zsets and shred them via ZADD/DEL. Pure correctness hardening; no operator action required unless your config already trips the new rule (in which case the error message names the offending key).

### What's Changed

* feat(alerts,console): `connection_drift` detector + `queue-insights:migrate-aliases` command (0a33c7b)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.17.0...0.18.0

## 0.17.0 - 2026-05-13

### Highlights

- **Horizon supervisor queue auto-discovery.** When `laravel/horizon` is installed, every `horizon.environments` supervisor's `{connection, queue}` is unioned into the Queues panel + pending/in-flight aggregation. Resolution mirrors Horizon's own `ProvisioningPlan` (`Str::is` glob env keys + recursive merge with `horizon.defaults`). Static `snapshots[]` entries still win on collision. Default-on; opt out with `QUEUE_INSIGHTS_HORIZON_AUTODISCOVER=false`.
- **`horizon.silenced` merge.** Classes silenced via Horizon — operator entries in `config/horizon.php`, or upstream packages writing at boot (`spatie/laravel-health` writes `Spatie\Health\Jobs\HealthQueueJob` when `silence_health_queue_job` is on) — are now suppressed across the Silenced tab, failure-rate detectors, notifications, and the failed-jobs SQL exclusion. Strictly additive; no operator action needed.
- **`connection_aliases` fixes drift-induced pending invisibility.** When a job was dispatched on connection `A` but processed by a worker bound to connection `B` (both pointing at the same physical Redis DB), the package wrote `pending-zset:A:{queue}` and tried to clear `pending-zset:B:{queue}` — keys never met, rows orphaned, the dashboard panel scoped to the worker connection showed zero pending for a queue with depth. Publishing the alias map collapses both sides onto a canonical name:
  ```php
  'connection_aliases' => [
      'redis' => 'redis-staging',
      'redis-staging' => 'redis-staging',
  ],
  
  
  
  
  
  
  
  
  
  ```
  Affects every connection-keyed Redis key (pending / inflight / wait zsets, live + history + samples counters, per-class roster + duration / processed-total / failed-total / last_run, completed-stream) and the matching Prometheus `connection` labels. Validator rejects transitive chains and mutual cycles at boot; identity mappings are allowed. Legacy `?qk=`, `?qopen=`, and `/queue-insights/{connection}` URLs canonicalise transparently.

### Breaking changes

- Dashboard Queues panel surfaces Horizon supervisor queues by default. See [UPGRADING.md](UPGRADING.md#upgrading-from-016-to-017) for the opt-out.
- `horizon.silenced` entries now suppressed in Queue Insights surfaces. See [UPGRADING.md](UPGRADING.md#upgrading-from-016-to-017).
- Prometheus `connection` label switches to the canonical alias when `connection_aliases` is published — alert rules / Grafana panels referencing the pre-aliased name need a `relabel_configs` rule. See [UPGRADING.md](UPGRADING.md#upgrading-from-016-to-017) for the label inventory.

### What's Changed

* feat: Horizon integration wave — supervisor autodiscovery, silenced-job merge, connection_aliases (99f0256)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.16.1...0.17.0

## 0.16.1 - 2026-05-13

A new pending-orphan cleanup command, a `qi-time` UTC bug for hosts on `CarbonImmutable`, and a perf wave that drops warm dashboard Redis round-trips by ~92 % on the seeded 8-queue bench.

### Highlights

- **`php artisan queue-insights:purge-pending {connection} {queue}` command.** One-shot operator tool for the post-0.15→0.16 cutover window — drops a pending-tracking zset plus every per-uuid hash whose stored queue matches the target. Dry-run by default; `--force` to actually delete. Refuses to run against the connection's CURRENT default queue unless `--allow-live-queue` is set, so a misfired invocation can't shred in-flight pending visibility on the live default. Uses an atomic `RENAME` to a per-invocation temp key before walking entries, so concurrent producers can't race the purge into deleting a uuid that landed mid-scan. Hash reads + deletes are pipelined in chunks of 500. See the [`UPGRADING.md` cutover-window note](UPGRADING.md#upgrading-from-015-to-016) for invocation examples.
- **`qi-time` blade component now emits UTC `datetime` for `CarbonImmutable` inputs.** The 0.16.0 perf change collapsed `$carbon->copy()->utc()` into `$carbon->utc()` to avoid the per-call `copy()` allocation. That works for the mutable `Carbon` (mutates in place + returns `$this`) but breaks `CarbonImmutable`, which returns a new instance without touching the receiver — so on hosts that ship `Date::use(CarbonImmutable::class)` the `datetime` attribute and `aria-label` rendered in the original timezone while the JS hydrator parsed them as UTC. Capture `$utcCarbon = $carbon->utc()` and use that for the UTC output paths; Carbon callers still avoid the `copy()` allocation. Regression test covers `CarbonImmutable` in a non-UTC offset.
- **Warm dashboard Redis cost: 238 → 16–18 round-trips per render.** A four-patch pipelining wave (`PendingJobsReader::hydrate`, `QueueRowsBuilder` snapshot fan-out, cross-queue `ZRANGEBYSCORE` aggregation across pending/delayed/in-flight categories, and `IssueDetector::detectAll`) collapses the dashboard's wire:poll Redis I/O to two pipelined round-trips for the queue grid + two for the alert detectors. On production Redis at 0.5–1 ms RTT/cmd, warm render drops from ~120–240 ms Redis-bound to ~8–65 ms. Same numbers verified with seeded 8-queue × 50-inflight × 50-pending × 25-delayed × 80-failed × 20-class state via `autoresearch/dashboard-warm-bench.php`.
- **`retention.duration_samples_cap` config knob.** Caps the per-class `duration:samples` list that backs the `slow_p95` detector + the Classes-tab p95 column. Default `500` preserves the previous hardcoded behaviour (no silent shrink on upgrade); operators running into per-class Redis memory pressure can lower it (e.g. `200`) to trim that list ~60 % at a small loss in percentile stability. Wired into both the listener-side LTRIM and the dual-write Lua so the aggregate and per-connection lists share one source of truth.
- **"Needs attention" banner on the schedule task modal.** When a task has recent missed / hung / failed / skipped runs, the per-task drilldown surfaces a one-row summary at the top with `last_failed_at` so an operator opening the modal sees what changed without scrolling through the runs table.

### What's Changed

* feat: `queue-insights:purge-pending` command + "needs attention" banner on the schedule task modal (df521a5, 5dddf19, dfb51b5)
* fix(qi-time): emit UTC `datetime` + `aria-label` for `CarbonImmutable` inputs (53d03e2)
* fix(alerts): skip the per-uuid `HGET` when the head age is under the threshold — restores the pre-extraction snapshot-command behaviour on `OldestPendingDetector` / `StuckInFlightDetector` (458fb73)
* perf(blade): `qi-time` drops `Carbon::copy()` and dedupes the UTC `format()` call — ~80 % per-invocation cost cut (a45fd0b)
* perf(pending): pipeline the `HGETALL` fan-out in `PendingJobsReader::hydrate` — 150 sequential reads → 1 pipelined round-trip on the seeded bench (830ef02)
* perf(dashboard): batch `QueueRowsBuilder` per-queue snapshot reads via new `QueueInsights::queueRowSnapshots()` + `Support\QueueRowSnapshotReader` (e9c7b54, f795617)
* perf(pending): pipeline cross-queue `ZRANGEBYSCORE` fan-out across pending / delayed / in-flight categories (82ddce1)
* perf(alerts): pipeline `IssueDetector::detectAll` across configured queues via new `Alerts\IssueDetectorBatch` + per-detector `evaluate()` split (d8ff2d6). The snapshot-command per-queue path (`detect()`) is unchanged.
* perf(memory): new `retention.duration_samples_cap` config + skip writing empty `batch_id` on pending hashes for non-batched jobs (3475e5c)
* refactor(views): extract `icon-close`, `icon-warning-triangle`, `icon-chevron-{left,right}`, `icon-error-circle`, `icon-info-circle` blade components — dedupes ~35 inline SVG copies (81c60e9, 1eb408a, 394b8ee, 2a23d29)
* simplify: extract `attentionReasons`, pipeline purge `HGET`/`DEL`, drop redundant `flags['any']` aggregate, hoist `$now` on `StalledDetector::evaluate`, dedupe seven inlined `Redis::connection(...)` callsites (d57832c, 583a3ab)
* chore: rector + pint mechanical cleanup (04b9d53)
* ci(run-tests): trim matrix from 25 to 9 cells with explicit floor / ceiling / mid coverage (a4f846f)
* docs(ai): move per-subsystem AI internals to `.ai/docs/` with a pointer index in `.ai/guidelines/architecture.md`; `CLAUDE.md` / `AGENTS.md` regen drops ~1450 inlined lines (57d03a4)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.16.0...0.16.1

## 0.16.0 - 2026-05-12

### Highlights

- **SQS pending-tracking fix for non-default queue names.** When the dispatcher omits `->onQueue()`, Laravel emits `JobQueued` with an empty `$event->queue` even though the driver routes to its configured default at push time. The producer-side listener previously fell back to the literal `'default'` while the worker-side cleanup keyed off the popped job's real queue — keys diverged, pending entries never cleared, and `oldest_pending` tripped on long-completed jobs. Hits any host whose `queue.connections.{conn}.queue` isn't literally `'default'` (Vapor / SQS with `SQS_QUEUE=staging_default` is the canonical case, but redis / database connections with a custom default queue had the same symptom). The new `CanonicalQueueKey::fromOrDefault($input, $connection)` helper resolves the connection's configured default at both producer and worker sites; the same path also fixes a parallel chain-lineage misattribution on chained SQS jobs. Reported via real-world adoption feedback.
- **Schedule dashboard render: 13,030 → 6 Redis round-trips per render.** The scheduler tab previously fanned out tens of thousands of serial commands per refresh (24 hourly aggregates × N tasks for stats; 24 × N for the sparkline; 600 HGETALLs for recent runs; N HGETs for the task index). Pipelined into single round-trips: `computeStatsForTasks`, `throughputSparkline`, `RunsQuery::collectMatchingRows`. `ScheduleReader::tasks()` now uses HMGET; empty-filter `countRuns` returns ZCARD. New `Support\RedisPipeline` helper consolidates the predis-vs-phpredis driver shim. On production Redis at ~1 ms RTT, render latency on a 157-task / 600-run benchmark drops from ~13 s to ~6 ms.
- **Schedule list-path payload tightened.** `ScheduleReader::recentRuns()` no longer hydrates `output`, `exception`, `skip_reason`, or `is_background` — the run-row blade doesn't render any of them, and `output` / `exception` can each grow to several KiB per row. With `scheduler.capture.output=full` and a high `max_output_bytes`, the previous fan-out could buffer multi-MB into a single Redis reply. The drilldown modal still hydrates the full payload via `runDetail()` / `runOutput()` (unchanged).
- **Chain-lineage queue canonicalisation.** `Bus::chain(...)->onConnection('foo')` (without `->onQueue()`) on a parent whose own `getQueue()` returned empty now correctly keys the chain-claim under `foo`'s configured default — previously it landed under the outer connection's default and the child's `JobQueued` RPOP missed silently. Regression test added.

### Breaking changes

- **`ScheduleReader::recentRuns()` row contract.** The list path returns `null` for `output` / `exception` / `skip_reason` and `false` for `is_background` regardless of stored value. Hosts consuming `RunsQuery` / `recentRuns()` directly and reading those four fields must migrate to `ScheduleReader::runDetail()`. See [UPGRADING.md#schedulereaderrecentruns-list-path-now-omits-output--exception--skip_reason--is_background](UPGRADING.md#schedulereaderrecentruns-list-path-now-omits-output--exception--skip_reason--is_background) for the full migration note.
- **Pending-zset key shape on connections whose default queue isn't `'default'`.** The producer now writes `pending-zset:{conn}:{configured-default}` instead of `pending-zset:{conn}:default`. Hosts with `queue-insights.snapshots` entries pointing at the old literal `'default'` need to update them to match the real queue (Vapor / SQS being the canonical case); pre-0.16 orphans on the old zset age out via the 24-h `pending.ttl_seconds`. See [UPGRADING.md#pending-zset-key-now-uses-the-connections-configured-default-queue](UPGRADING.md#pending-zset-key-now-uses-the-connections-configured-default-queue) for the snapshots realignment + cutover-window note.

### What's Changed

* fix(listeners): resolve connection-default queue when JobQueued carries an empty queue (62ede1d)
* fix: address code-review findings on the SQS pending-orphan fix (5ce1b4f)
* fix(chain-lineage): canonicalise queue under chain_connection in pushChainClaim (f2dfe3c)
* perf(scheduler): pipeline render fan-outs — 13k → 6 Redis round-trips (dd34d64..f1b4e9e, 6684578)
* fix(scheduler): omit `output` / `exception` / `skip_reason` / `is_background` on the recent-runs list path (6684578, bf79c61)
* fix(tests): clear pest-plugin-phpstan rules under `--error-format=github` (44cce28)
* chore: rector + pint mechanical cleanup (9aa6b74, 42bbb81, ff2fda8)
* docs(readme): add Upgrading section linking to UPGRADING.md (026b6ad)

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.15.0...0.16.0

## 0.15.0 - 2026-05-10

### Highlights

- **Global queue scope.** Click a queue row on the Queues tab to scope every list pane (Failed / Completed / Pending / Silenced) to that queue. Click again to toggle off. URL-shareable as `?qk={connection}:{queue}`. Mirrors the existing class scope on the Classes tab; both axes coexist. Pending/in-flight reads narrow to the scoped queue's zset directly so cross-queue traffic can't evict scoped rows from the candidate window. Completed reads route through the per-connection stream (~10 k cap) instead of the global aggregate (~250 cap) when only queue scope is active.
- **Inline scope strip.** A `Filtering by queue=… · class=…` row above the tab bar surfaces the active scope across every tab with a per-chip clear button. Clicking the already-selected class or queue clears the scope (toggle).
- **Retry badge.** Pending, in-flight, and completed rows render an orange `retry N` chip with a hover tooltip when the worker has picked a job up more than once. Backed by `attempts` stamped on the pending hash via the extended `MarkInFlight.lua` script.
- **Runtime column on Failed.** Failed rows now show runtime alongside Completed (was missing). Backed by a new `failed-runtime:{uuid}` side-key (30 d TTL) written by `RecordJobFailed` before the worker's `start:` stamp is consumed; `RowEnricher::failed()` row shape gains `duration_ms: ?int`.
- **Filter unification.** Both Failed and Completed dropdowns bind to the global `?ck=` class scope — picking on either pane scopes the other automatically. The collapsible `<details>` filter row was replaced with an always-visible inline toolbar.
- **Auto-reveal silenced rows.** Picking a silenced class on the Classes tab no longer reads as empty; Failed and Completed automatically include silenced rows when the active class scope IS a silenced class.
- **Silenced-tab failed click fix.** Clicking a row on the Silenced tab's Failed list now opens the modal. Previously the SQL silenced-exclusion stripped the row from the in-memory lookup; a DB fallback now fetches by id and re-applies path-level scope so deep links can't widen the surface.
- **Polish.** Tab-strip refresh + "See all N →" overview links now switch tabs reliably (Alpine scope-leak fix). Schedule per-page default 50 → 10. Job-list column shape unified across Completed / Failed / Silenced (5/2/2/2/1) so Job gets ~42% width. Tracking-gap drawer (Queues tab) gains a dark-mode contrast fix. Pending-row chips moved to the identifier line so long FQCNs get the full first row; UUIDs render full-width with CSS truncate. Delayed badge gains a tooltip with total delay + queued/runs absolute timestamps.

### Breaking changes

- Failed-pane class filter URL key removed: `?fk=` → `?ck=`. Bookmarks pinning a Failed-list class via `?fk=` need rewriting to `?ck=` (both panes now share the key). See [UPGRADING.md#failed-pane-class-filter-url-key-removed-fk--ck](UPGRADING.md#failed-pane-class-filter-url-key-removed-fk--ck) for migration steps.

**Full Changelog**: https://github.com/SanderMuller/laravel-queue-insights/compare/0.14.0...0.15.0

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
