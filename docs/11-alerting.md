# Alerting

Enable via `QUEUE_INSIGHTS_ALERTS_ENABLED=true`. Nine detectors run every snapshot tick (≈ every minute) against live Redis state:

| Rule               | Scope     | Fires when                                                                                                                                                                            |
|--------------------|-----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `depth`            | per-queue | `live:depth` ≥ a configured threshold                                                                                                                                                 |
| `stalled`          | per-queue | depth ≥ `min_depth` AND no worker pickups in `idle_seconds`                                                                                                                           |
| `oldest_pending`   | per-queue | the oldest runnable pending job has been waiting `seconds` (skips not-yet-due delayed jobs)                                                                                           |
| `stuck_inflight`   | per-queue | the longest-running in-flight job has been executing `seconds`                                                                                                                        |
| `failure_rate`     | per-class | `failed / (processed + failed)` ≥ `ratio` over the **current hour bucket** AND total ≥ `min_jobs`                                                                                     |
| `slow_p95`         | per-class | per-class p95 duration ≥ `class_threshold_ms[$class]` (opt-in per class)                                                                                                              |
| `snapshot_errored` | per-queue | the snapshot driver threw on the most recent tick (auto-clears on next success / 10-min TTL)                                                                                          |
| `backlog_growing`  | per-queue | least-squares depth slope over the recent samples ≥ `min_slope_per_minute` (opt-in, warms up after `min_samples` samples)                                                             |
| `connection_drift` | global    | pending rows present under a Laravel queue connection that isn't the configured canonical for that queue (opt-in, default off — see [Connection aliasing](13-connection-aliasing.md)) |

A dashboard-only watchdog (`snapshot_command_dead`) renders a top-level red banner when `live:depth` keys are absent for every configured queue — i.e. the snapshot command itself has been silent for ≥ 90 s.

Cooldown applies to **outbound notifications only** (key: `alert:cooldown:{rule}:{c}:{q}`, TTL `cooldown_seconds`). The dashboard always reflects live state.

## Alert on individual job failures (`job_failed`)

The nine rules above are poll-driven. `job_failed` is a tenth, **event-driven** rule (opt-in, default off): it fires once on a job's **final** failure (Laravel's `JobFailed` — i.e. retries exhausted), the same trigger as [spatie/laravel-failed-job-monitor](https://github.com/spatie/laravel-failed-job-monitor) — so you don't need both. On top of a bare per-failure ping it adds per-class **cooldown**, **silencing** (`queue-insights.silenced`), and the same multi-channel routing (Slack / mail / Sentry / log) as every other rule. Because the only signal is the event, it works on **any** queue driver — no Redis snapshot required.

```php
'job_failed' => ['enabled' => true, 'severity' => 'warning', 'notify' => true],
```

A typed `SanderMuller\QueueInsights\Events\JobFailedAlert` event is dispatched (cooldown-gated, silencing-filtered) carrying the job class, connection, queue, uuid, and the live exception — subscribe to forward it anywhere.

**`vs failure_rate`:** pick `job_failed` for "tell me about every failure", `failure_rate` for "tell me when a class is failing *a lot*" (a ratio over the hour bucket). They're complementary; enabling both gives an alert per incident *and* a trend alert.

> [!IMPORTANT]
> Unlike the poll-driven rules (which notify from the snapshot command), `job_failed` notifies **synchronously inside the worker**. With Slack/mail/Sentry enabled, the first failure of each class per cooldown window blocks the worker on that network call. For high-failure-volume apps, set `'notify' => false` to keep the `JobFailedAlert` event firing while skipping the package's synchronous channels, and dispatch your own queued notification from a listener.

## Config example

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
        // Per-job failure alert (event-driven, opt-in). See "Alert on
        // individual job failures" below. `notify => false` keeps the
        // JobFailedAlert event but skips this rule's package channels.
        'job_failed' => ['enabled' => false, 'severity' => 'warning', 'notify' => true],
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
        'connection_drift' => ['enabled' => false, 'severity' => 'warning'],
    ],

    'channels' => [
        'log' => ['enabled' => true, 'level' => 'warning'],
        'slack' => ['enabled' => false, 'webhook_url' => env('QUEUE_INSIGHTS_SLACK_WEBHOOK')],
        'mail' => ['enabled' => false, 'to' => ['ops@example.com']],
        'sentry' => ['enabled' => false],
    ],
],
```

> **Heads up — `oldest_pending` / `stuck_inflight` need pending tracking.**
> Both detectors read `pending-zset:*` / `inflight-zset:*` populated by the `RecordJobQueued` / `RecordJobProcessing` listeners. With `pending.enabled = false` they short-circuit at runtime and a one-off boot warning lists which rules were tripped. Either re-enable pending tracking or disable those rules.

## Notification channels

The package ships four channels out of the box:

- **`log`** — zero-dep, on by default; one structured log line per issue at the configured level (`alerts.channels.log.level`).
- **`slack`** — `Http::post` to a Slack-compatible incoming webhook (works with Slack, Mattermost, Rocket.Chat). Block Kit payload with severity-coloured attachment; falls back to plain `text` if the receiver rejects Block Kit. Set `QUEUE_INSIGHTS_SLACK_WEBHOOK` and `alerts.channels.slack.enabled = true`. `QUEUE_INSIGHTS_SLACK_CHANNEL` (queue alerts) and `QUEUE_INSIGHTS_SCHEDULER_SLACK_CHANNEL` (scheduler alerts) are optional display labels surfaced in the dashboard's alert-rules panel — they don't override the webhook's destination, since Slack incoming-webhooks bind the channel server-side at creation time.
- **`mail`** — uses Laravel's first-party mail channel; subject prefix `[Queue Insights] {severity}: {rule} on {target}`. Recipients from `alerts.channels.mail.to` (array of addresses).
- **`sentry`** — captures each issue into your application's existing Sentry project as a grouped event. No DSN config here: the channel uses whatever Sentry hub the host has initialised. Recommended setup is [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) with `SENTRY_LARAVEL_DSN` set (any initialised `sentry/sentry` hub works too); then set `alerts.channels.sentry.enabled = true`. Severity maps fixed — `critical → error`, `warning → warning` — and events fingerprint per `[queue-insights, rule, target]` so Sentry groups one issue per rule+target instead of opening a new one each snapshot tick. Tags (`queue_insights.rule`/`severity`/`connection`/`queue`/`job_class`) and the full issue context (as a `queue-insights` context block) ride along.

`slack`, `mail`, and `sentry` feature-detect their underlying dependency (`Illuminate\Http\Client\Factory`, `mail.manager`, and — for sentry — a *bound* Sentry hub client, not merely the loaded SDK) — if it's missing the channel is silently skipped, and the dashboard's alert-rules panel shows the reason (sentry's row reads `SDK not installed` when the package is absent, or `hub not configured` when the SDK is present but no DSN/hub is initialised). Because sentry requires a live client, a misconfigured scheduler-sentry-only setup falls back to the queue-side channels rather than dropping the alert.

## Adding more channels (Discord, Teams, PagerDuty, Telegram, …)

The package emits a `SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification` and routes it through `SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable`, exactly as Spatie's alerting packages and Horizon do. To add a destination:

1. Install the matching `laravel-notification-channels/*` package (`discord`, `microsoft-teams`, `pagerduty`, `telegram`, `vonage`, …).
2. Extend `QueueAlertNotification` to add the channel to `via()` and a `to{Channel}()` method, OR override `QueueInsightsNotifiable` and add `routeNotificationFor{Channel}()`.
3. Bind your override in your `AppServiceProvider`:

   ```php
   $this->app->bind(QueueAlertNotification::class, MyQueueAlertNotification::class);
   $this->app->bind(QueueInsightsNotifiable::class, MyNotifiable::class);
   ```

## Typed events (always fire)

Each rule fires a typed event regardless of which channels are enabled — host apps can hook `Event::listen(...)` for custom routing:

- `QueueDepthExceeded` (existing — added trailing nullable `?string $severity`)
- `QueueStalled`, `OldestPendingAging`, `StuckInFlight`, `SnapshotErrored`
- `JobClassFailureRateExceeded`, `JobClassP95Exceeded`
- `BacklogGrowing`

## Active-rules panel

The dashboard footer renders a read-only summary of `alerts.rules` + `alerts.channels` so operators can verify what's monitored without SSH'ing into the server. Edit the config file to change anything — there is no runtime mutation surface.

## Migrating from the 0.x `alerts.thresholds` shape

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

## Silencing noisy jobs

Mirrors Horizon's `horizon.silenced` knob: list job-class FQCNs whose **failures** should be suppressed from the dashboard's Failed list, the headline failed-tile, the throughput sparkline's failed series, the `failure_rate` alert detector, and outbound notifications.

```php
'silenced' => [
    App\Jobs\IntermittentlyFailingJob::class,
    App\Jobs\ThirdPartyApiSometimesFlakes::class,
],

// Glob fallback for whole namespaces or related classes. Exact `silenced`
// entries are matched first; `silenced_patterns` is `Str::is`-style and
// matches case-insensitively, same as `silenced`.
'silenced_patterns' => [
    'App\\Jobs\\Reports\\*',
    'App\\Jobs\\*Sync',
],
```

Counter writes (`qi:processed:{class}:{bucket}`, `qi:failed:{class}:{bucket}`, `qi:classes`) are preserved — silencing is a read-side filter only, so removing a class from the list immediately re-surfaces its history without any backfill. The class rows table keeps showing throughput / p95 / max for silenced classes with a muted `silenced` badge so you can still triage them.

| Surface                                                          | Behaviour under silencing                                                                                                                                    |
|------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Failed list (Failed tab)                                         | Hidden by default. The "Show silenced" checkbox on the failed-pane filter form reveals them; URL-shareable as `?fs=1`.                                       |
| Headline `failed_past_hour` + throughput sparkline failed series | Silenced classes excluded. Processed series stays exact.                                                                                                     |
| `failure_rate` alert detector                                    | Returns null for silenced classes — no event, no notification, no cooldown burned.                                                                           |
| `slow_p95` alert detector                                        | Unchanged — silencing is a failure-noise filter, not a perf filter. Exclude noisy classes from `class_threshold_ms` if you want their perf alerts muted too. |
| Class rows table                                                 | Row stays, marked with a muted `silenced` badge inline next to the FQCN. Operators still see throughput / p95 / max for silenced classes.                    |
| Modal-by-uuid + chain-lineage click-through + batch-detail items | NOT filtered. Silencing is a list-level filter; uuid-addressed lookups always resolve so a batched member or chain parent stays clickable.                   |
| `qi:failed:{class}:{bucket}` Redis counters + `qi:classes` zset  | Still written by the listeners. Silencing is reversible without losing history.                                                                              |

The bulk-retry uuid collector inherits the same SQL exclusion path — bulk-retry actions on the default-filter view never queue silenced classes for retry. Toggle "Show silenced" first if you want them in the bulk set.

### Horizon-silenced jobs

When `laravel/horizon` is installed, entries from Horizon's own `config('horizon.silenced')` are automatically merged into the same filter set — operator-edited `config/horizon.php` entries and upstream packages writing to it at boot (e.g. [spatie/laravel-health](https://github.com/spatie/laravel-health)'s `silence_health_queue_job` flag, which adds `Spatie\Health\Jobs\HealthQueueJob`) take effect without a duplicate `queue-insights.silenced` entry. Merge is read-only; we never write back to Horizon's config.
