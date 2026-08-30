# Scheduler observability

Enable via `QUEUE_INSIGHTS_SCHEDULER_ENABLED=true`. Off by default, existing queue-insights users opt in.

When on, the package listens on Laravel's `Illuminate\Console\Events\Scheduled*` events and records:

- **Per-task definition snapshots**: cron expression, command summary, queue connection, `runInBackground`, `withoutOverlapping`, `onOneServer`. Snapshot is hash-stable; a `php artisan schedule:list`-style render is rebuilt from these.
- **Per-run records**: `Starting`, `Finished` (exit code + runtime), `Failed` (exception class + message), `Skipped` (reason), `BackgroundTaskFinished` (parent process exits before the child; the run is closed off the running pointer). Output capture is configurable: `off` / `metadata` (exit code only) / `full` (stdout/stderr after the bound `PayloadSanitizer` pass + byte cap).
- **Counters + 24h aggregates**: per-task processed / failed / skipped / hung / missed counts and rolling p95 runtime.

```bash
# .env
QUEUE_INSIGHTS_SCHEDULER_ENABLED=true
QUEUE_INSIGHTS_SCHEDULER_CAPTURE=metadata   # off | metadata | full
QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED=false
```

## Dashboard panel

When the dashboard is mounted and `scheduler.dashboard.enabled = true`, a lazy-loaded **Scheduled tasks** panel renders below the queue panes. Empty-state copy guides first-time hosts; the panel hides itself when scheduler observability is disabled. Gate via the existing `viewQueueInsights` ability, or define a narrower `viewScheduleInsights` Gate to gate scheduler reads independently.

Click a row in the **Tasks** card to open the per-task drilldown (cron expression + flag pills, 24h tile grid, host-distribution bar (suppressed for single-host tasks), recent-runs table scoped to the task. Click a row in **Recent runs** to open the per-run drilldown) exception block (failed runs), output viewer (full-capture only; closure tasks render an "output capture not supported" hint), skip-reason explainer, correlated-jobs section listing every job uuid the run dispatched (click-through opens the queue-side modal). Both modals are URL-bound (`?s_tk=` + `?s_rid=`) so deep-links round-trip; aged-out runs render an "Expired" empty state. Each modal carries a markdown-export copy button that hands its full context to AI agents or trackers, for a task that includes the "Needs attention" reasons, 24h stats, host distribution and the recent-runs table.

The package rebuilds the snapshot from the live `Schedule::events()` when a scheduler-relevant console command starts, `schedule:*` and `queue-insights:*` by default. Unrelated artisan commands and web requests never touch Redis for it, and the roster is only ever written from the console, where `withSchedule()` and `routes/console.php` tasks are actually registered. A host that has never run the scheduler sees an empty panel until `schedule:run` (or `queue-insights:schedule:list`) fires once. Hosts that drive the scheduler through their own wrapper command can list it in `scheduler.snapshot_rebuild_commands` (exact names, or a trailing `*` for a prefix). Hosts that pre-seed the snapshot keys themselves (custom import script, fixture seeder, etc.) can opt out entirely with `QUEUE_INSIGHTS_SCHEDULER_SNAPSHOT_REBUILD=false`.

## CLI

```bash
php artisan queue-insights:schedule:list    # snapshot table: cron, command, last run, counters
php artisan queue-insights:schedule:sweep   # one-off sweep: flag missed + hung runs, dispatch events
```

Run the sweep on its own short cron (`* * * * *`). The sweeper's own work is detect-only; it does not poll Redis on hot-path tick events.

## Missed + hung detection

A run is **missed** when the cron expression's next-fire timestamp passes without a `Starting` event landing inside `sweeper.drift_seconds` (default 90 s). The sweeper waits out the full drift grace before judging. An expected fire is only evaluated once `now ≥ expected_at + drift_seconds`, so a `Starting` that arrives late-but-within-drift (Vapor cold start, EventBridge jitter) has its full chance to land and suppress the miss before the fire is judged. A run is **hung** when no `Finished` / `Failed` event arrives within `expected_runtime + hung.grace_seconds` (default 300 s); expected runtime is the rolling p95 from aggregates and falls back to `grace_seconds` alone for tasks with fewer than `hung.min_runs_for_p95` (default 10) recorded runs.

A single isolated missed fire is common infra noise on a per-minute scheduler (a Vapor/EventBridge tick that lands late, or a transient Redis blip that drops the `Starting` write) so the missed **alert** is debounced by `sweeper.min_consecutive_misses` (default 2). The synthetic `missed` row is always recorded for the dashboard, but `ScheduledTaskMissed` only dispatches once that many consecutive expected fires go unobserved (prior synthetic `missed` rows don't count as runs, so a sustained gap accumulates correctly). Set it to 1 to alert on every isolated miss.

> A task with no recorded run history (e.g. a freshly deployed schedule) can trip the threshold on its first sweeps, since its never-observed past fires count toward the streak. This is transient (it clears as soon as the task records its first runs) and cooldown-bounded; raise `min_consecutive_misses` if a noisy deploy window matters.

When `scheduler.alerts.enabled = true`, missed/hung/failed detections dispatch typed events with per-`(taskKey, rule)` cooldown (`scheduler.alerts.cooldown_seconds`, default 900). Cooldown gates the **event dispatch itself**, when an alert is suppressed by cooldown, no event fires. Host listeners on `ScheduledTaskFailed` / `Missed` / `Hung` therefore only see the leading edge of an alerting condition; subsequent ticks within the cooldown window are silent until cooldown expires.

Notifications additionally require the package-wide `alerts.enabled` master switch to be on, typed events fire under `scheduler.alerts.enabled` alone, but log / slack / mail emission is gated on **both** flags so a host running with `alerts.enabled=false` for queue alerts doesn't suddenly start paging on scheduler events after upgrade.

```text
SanderMuller\QueueInsights\Events\ScheduledTaskMissed   { taskKey, task, expectedAtMs }
SanderMuller\QueueInsights\Events\ScheduledTaskHung     { taskKey, runId, task?, … }
SanderMuller\QueueInsights\Events\ScheduledTaskFailed   { taskKey, runId, task, … }
```

Scheduler alerts route through the same `QueueAlertNotification` pipeline as queue alerts, `log` / `slack` / `mail` / `sentry` channels, Spatie-style notifiable, host-extensible. Operators get one mental model and one set of channels to wire.

### Per-domain channel routing

Populate `scheduler.alerts.channels` to send scheduler alerts to a different Slack channel / mail recipient list / log channel. When the scheduler block has at least one channel explicitly enabled, scheduler-scoped issues read it; otherwise they fall back to `alerts.channels`. Single-list installs (only `alerts.channels` populated) Just Work without any extra config:

```php
'scheduler' => [
    'alerts' => [
        'enabled' => env('QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED', false),
        'cooldown_seconds' => 900,
        'channels' => [
            'slack' => [
                'enabled' => true,
                'webhook_url' => env('QUEUE_INSIGHTS_SCHEDULER_SLACK_WEBHOOK'),
                'channel' => '#cron-watch',
            ],
        ],
    ],
],
```

Scheduler-scoped Slack payloads carry a `Run URL` field that deep-links into the dashboard's per-run modal (`?s_rid={taskKey}:{runId}`). Missed runs link to the per-task modal (`?s_tk={taskKey}`) instead.

The typed `ScheduledTaskFailed` / `ScheduledTaskMissed` / `ScheduledTaskHung` events keep firing alongside the notification path, so existing host listeners stay wired. The cooldown key namespace moved from `sched:alert:cooldown:*` to `alert:cooldown:scheduled_task_*:task:{taskKey}` for parity with queue-side alerts, see UPGRADING for the one-shot Redis cleanup.

## External heartbeat

In-process detection cannot catch a fully-dead scheduler (`schedule:run` not running at all). The sweeper command **POSTs out** to an operator-supplied heartbeat URL after every successful tick. A Healthchecks.io / Cronitor / Oh Dear / Sentry Crons / Better Stack ping endpoint. Configure the destination URL and the receiving SaaS alerts when posts go silent:

```php
'scheduler' => [
    'heartbeat' => [
        'enabled' => true,
        'url' => env('QUEUE_INSIGHTS_SCHEDULER_HEARTBEAT_URL'),
    ],
],
```

Payload is a small JSON body (`host_id`, `timestamp`, `tasks_swept`); the sweeper times the request out at 5 s and logs a warning on failure rather than blocking. The host owns the receiving uptime monitor; the package owns the outbound POST.

## Retention

Per-run records age out at `scheduler.retention.run_ttl_seconds` (default 7 d). The recent-runs index is capped at `runs_index_max` entries (default 10 000). Per-run job zsets (`qi:sched:run-jobs:{runId}`) are capped at `run_jobs_max` (default 5 000) so a fan-out task that dispatches a very large number of jobs cannot grow the index unbounded, oldest by score evicted first.
