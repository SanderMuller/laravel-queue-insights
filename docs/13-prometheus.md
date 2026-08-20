# Prometheus

Enable via `QUEUE_INSIGHTS_PROMETHEUS_ENABLED=true`. Mounts `GET /metrics` (path configurable) exposing queue-insights state in Prometheus 0.0.4 text format — or OpenMetrics 1.0.0 when the scraper sends `Accept: application/openmetrics-text` (Prometheus negotiates this automatically). Default-off; adoption is opt-in.

Auth is **fail-closed**: the package's default middleware refuses with `403` unless `prometheus.token` (preferred for shared infra) or `prometheus.allow_ips` (CIDR list) is configured. There is no silent open default.

```bash
# .env
QUEUE_INSIGHTS_PROMETHEUS_ENABLED=true
QUEUE_INSIGHTS_PROMETHEUS_TOKEN=long-random-string
```

```yaml
# prometheus.yml
scrape_configs:
  - job_name: laravel-queue-insights
    metrics_path: /metrics
    bearer_token: long-random-string
    static_configs:
      - targets: ['app.example.com']
```

## Metric catalogue

| Metric                                             | Type    | Labels                                                                       | Notes                                                                                                           |
|----------------------------------------------------|---------|------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| `queue_insights_queue_depth`                       | gauge   | `connection`, `queue`                                                        | Mirrors snapshot loop output. Pair with `queue_insights_snapshot_alive`.                                        |
| `queue_insights_inflight_jobs`                     | gauge   | `connection`, `queue`                                                        | `ZCARD inflight-zset`.                                                                                          |
| `queue_insights_pending_jobs`                      | gauge   | `connection`, `queue`                                                        | Runnable now (`available_at <= now`).                                                                           |
| `queue_insights_delayed_jobs`                      | gauge   | `connection`, `queue`                                                        | Not yet runnable (`available_at > now`).                                                                        |
| `queue_insights_oldest_pending_age_seconds`        | gauge   | `connection`, `queue`                                                        | 0 when empty.                                                                                                   |
| `queue_insights_oldest_inflight_age_seconds`       | gauge   | `connection`, `queue`                                                        | 0 when empty.                                                                                                   |
| `queue_insights_jobs_processed_total`              | counter | `class`, `connection`                                                        | True monotonic INCR — safe for `rate()` / `increase()`.                                                         |
| `queue_insights_jobs_failed_total`                 | counter | `class`, `connection`                                                        | Same.                                                                                                           |
| `queue_insights_job_duration_count_total`          | counter | `class`, `connection`                                                        | Mean = `rate(sum) / rate(count)` Prometheus-side.                                                               |
| `queue_insights_job_duration_sum_seconds_total`    | counter | `class`, `connection`                                                        | Seconds (HINCRBY `sum_ms` ÷ 1000).                                                                              |
| `queue_insights_job_duration_max_seconds`          | gauge   | `class`, `connection`                                                        | Lifetime max. Use `max_over_time()` for windowed maxima.                                                        |
| `queue_insights_alert_active`                      | gauge   | `rule`, `connection`, `queue`, `severity` (+ `class` for class-scoped rules) | Always 1 when present; absent series = no alert. Use `OR on() vector(0)` Grafana-side to render gaps as 0.      |
| `queue_insights_snapshot_alive`                    | gauge   | `connection`, `queue`                                                        | 1/0. **Use this in alerts**, not `_age_seconds`.                                                                |
| `queue_insights_snapshot_age_seconds`              | gauge   | `connection`, `queue`                                                        | **Omitted** when the snapshot key is absent (so alerts can use `absent(...)` cleanly instead of clamping to 0). |
| `queue_insights_snapshot_errors_total`             | counter | `connection`, `queue`                                                        | Monotonic INCR — paired with the existing 10-min `snapshot:error:*` boolean.                                    |
| `queue_insights_exporter_collect_duration_seconds` | gauge   | (none)                                                                       | Wall-clock seconds of the previous collect cycle.                                                               |

Per-class metrics (`*_processed_total`, `*_failed_total`, duration aggregates) are **opt-in by class** to bound cardinality. Default `class_filter.mode = allow_list` with empty `classes` → no per-class metrics emitted. Three modes:

```php
// config/queue-insights.php
'prometheus' => [
    'class_filter' => [
        // 'allow_list'        — only emit for the FQCNs in `classes` (DEFAULT)
        // 'allow_all'         — emit for every class on classes:{connection}
        // 'top_n_by_recency'  — top N most-recently-seen per connection (recency, NOT throughput)
        'mode' => 'allow_list',
        'classes' => [
            App\Jobs\GenerateReport::class,
            App\Jobs\SyncCustomer::class,
        ],
        'top_n' => 50,
    ],
],
```

A two-tier cache (per-request memoise + 5 s Redis cache, key `prom:cache:rendered:{flavour}`) bounds thunder-herd when multiple Prometheus replicas scrape concurrently. Set `prometheus.cache_ttl_seconds = 0` to disable both layers for instant reads.

Each metric family has its own toggle under `prometheus.metrics.*` (default-on) — disable any family the host doesn't need to keep the scrape body lean.

## Scheduler metrics

When `scheduler.enabled = true` AND each per-family toggle below is set, the exporter emits scheduler-side families. **Default OFF** — adoption is opt-in per family (mirrors the per-class queue metrics stance).

| Metric                                                    | Type    | Labels           | Notes                                                                                                                                                                                                 |
|-----------------------------------------------------------|---------|------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `queue_insights_scheduled_task_runs_total`                | counter | `task`, `status` | Status: `success` (= `total_runs - total_failed`), `failed`, `skipped`. Hung + missed are separate families below.                                                                                    |
| `queue_insights_scheduled_task_runtime_sum_seconds_total` | counter | `task`           | Lifetime runtime sum, seconds. Pair with `queue_insights_scheduled_task_runs_total` for mean: `rate(sum) / rate(runs_total{status=~"success\|failed"})`. Sample omitted until the first finished run. |
| `queue_insights_scheduled_task_last_run_timestamp`        | gauge   | `task`, `status` | Unix ts (seconds) of last run per status. Page on `time() - queue_insights_scheduled_task_last_run_timestamp{status="success"} > N`. Sample omitted when no run of that status exists.                |
| `queue_insights_scheduled_task_hung_total`                | counter | `task`           | Detections from `HungTaskReconciler`.                                                                                                                                                                 |
| `queue_insights_scheduled_task_missed_total`              | counter | `task`           | Detections from `MissedRunReconciler`.                                                                                                                                                                |
| `queue_insights_scheduled_task_in_flight`                 | gauge   | `task`           | 1 when the task is mid-run (Started without Finished/Failed). Sample omitted when not running.                                                                                                        |
| `queue_insights_scheduled_snapshot_age_seconds`           | gauge   | (none)           | Seconds since the schedule snapshot was last rewritten on app boot. **Omitted when never written** (alerts use `absent(...)` cleanly).                                                                |
| `queue_insights_scheduled_sweeper_age_seconds`            | gauge   | (none)           | Seconds since `MissedRunReconciler` last completed a tick. Alert on `> 2 × sweeper.sweep_seconds`.                                                                                                    |

Toggle each family independently:

```php
'prometheus' => [
    'metrics' => [
        // ...
        'scheduler_runs_total' => true,
        'scheduler_runtime_sum' => true,
        'scheduler_last_run_timestamp' => true,
        'scheduler_hung_total' => true,
        'scheduler_missed_total' => true,
        'scheduler_in_flight' => true,
        'scheduler_snapshot_age' => true,
        'scheduler_sweeper_age' => true,
    ],

    // Per-task cardinality control. Task rosters are typically <100,
    // so the default is `allow_all`. `top_n_by_recency` is intentionally
    // not supported — see internal/specs/cron-monitoring/07-platform-extensions.md §1.3.
    'task_filter' => [
        'mode' => 'allow_all',  // | allow_list
        'tasks' => [],          // taskKey list, used when mode = allow_list
    ],
],
```

`runtime_max_seconds` is intentionally NOT shipped in v1 — would need a Lua HSET-IF-GREATER write path. Operators who need lifetime max can compute `max_over_time(queue_insights_scheduled_task_runtime_sum_seconds_total[N])` Prometheus-side as a coarse proxy, or run the per-task duration sparkline in the dashboard for exact values.

## Push gateway (short-lived workers, CLI)

For processes that exit before any scrape can land, `php artisan queue-insights:prometheus-push` does a one-shot collect + PUT to a configured Pushgateway. Long-running workers should be **scraped, not pushed** — push-mode is for CLI scripts and scheduled tasks where pull-mode can't reach the process.

```bash
# .env
QUEUE_INSIGHTS_PUSHGATEWAY_URL=https://pushgateway.example/metrics
QUEUE_INSIGHTS_PUSHGATEWAY_JOB=laravel-queue-insights
QUEUE_INSIGHTS_PUSHGATEWAY_INSTANCE=worker-01   # required for clustered hosts
```

The command **fails closed** when `pushgateway.instance` is unset and `--accept-shared-grouping` is not passed: clustered hosts that share a `job` label without distinct `instance` values silently overwrite each other's pushed metrics. Pass `--accept-shared-grouping` once you've confirmed single-replica semantics, or set `instance` per-replica.

```bash
php artisan queue-insights:prometheus-push                           # PUT metrics
php artisan queue-insights:prometheus-push --delete                  # DELETE the grouping
php artisan queue-insights:prometheus-push --accept-shared-grouping  # opt out of the instance guard
```

Exit codes mirror Symfony Console convention: `0` success, `1` Pushgateway HTTP failure, `2` config error (missing URL / unset instance without override).
