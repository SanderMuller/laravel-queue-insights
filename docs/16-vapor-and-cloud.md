# Vapor and Laravel Cloud

Both platforms run your queues on SQS and manage the worker processes for you. Three consequences follow: queues are addressed by URL rather than by name, queue names carry an environment suffix, and Horizon is often configured without ever running. The package handles all three without configuration.

Everything driven by queue events works on either platform out of the box, throughput, wait time, runtimes, failures, chains, batches, the scheduler panel. Those listen on Laravel's own queue events, which every driver fires. Only the snapshot side (depth, in-flight, delayed) talks to the driver at all.

## Both platforms

### List the queues to snapshot

Neither platform tells the package which queues exist, so `snapshots[]` is yours to fill in:

```php
// config/queue-insights.php
'snapshots' => [
    ['connection' => 'cloud', 'queue' => 'default'],
    ['connection' => 'cloud', 'queue' => 'stats'],
],
```

Use the **logical** queue name. The one you dispatch to. Naming the suffixed queue works too; both resolve to the same key.

### One queue, two names

An SQS connection with a `suffix` (Vapor's `SQS_SUFFIX`, and Laravel Cloud's per-environment suffix, which is always set) gives one queue two names:

| | example | where it shows up |
|---|---|---|
| logical | `stats` | what you dispatch to, what `snapshots[]` lists, what the dashboard shows |
| physical | `stats-abc123` | what AWS knows, what the queue URL ends with, what `failed_jobs.queue` stores |

The package keys everything on the logical name (dashboard rows, snapshot metrics, pending tracking, alert scopes, Prometheus labels) and translates at the edges. There is nothing to configure.

::: warning Upgrading from before 0.32
Earlier versions keyed whichever name reached a given code path, so a suffixed queue rendered as two dashboard rows and its pending entries never cleared. Keys under the physical name are not migrated: they age out on their own TTLs, or clear now with `php artisan queue-insights:purge-pending {connection} {physical-name}`. Prometheus `queue` labels move from physical to logical, so pinned dashboards and alert rules need updating. See [UPGRADING.md](https://github.com/SanderMuller/laravel-queue-insights/blob/main/UPGRADING.md).
:::

### Depth numbers are approximations

SQS reports `ApproximateNumberOfMessages`, and the counts lag reality, especially across a scaling event. They work for trends and alert thresholds, but do not read them as an exact backlog. Redis and database connections report exact counts.

### Storage Redis is not a cache

The package writes its own keyspace to the connection named by `redis_connection`, and every key carries a TTL sized for its retention window. Point that at a Redis you control rather than at an instance configured to evict under memory pressure: an LRU policy drops insight keys well before their TTLs, so the dashboard loses history without reporting an error.

## Laravel Cloud

`cloud` is a wrapper rather than a queue backend of its own. `Illuminate\Foundation\Cloud` injects a `cloud` connection whose real SQS configuration sits nested under a `connection` key, and registers a connector that delegates to Laravel's SQS connector. Queue Insights unwraps that same level, so snapshots work with no `driver_overrides` entry.

To see what is attached to the environment, read the list Cloud injects:

```sh
php artisan tinker --execute="print_r(config('queue.connections.cloud.queues'));"
```

Then list the ones worth watching under `snapshots[]`.

If the app ran an earlier version, drop any `driver_overrides.cloud` entry. Before 0.32 the connection fell through to the null driver and logged `unknown queue driver` on every tick, and an override was the usual way to quieten it. An override still wins over the built-in support, so leaving it in place keeps snapshots dead, and now without the warning that used to point at it.

Credentials resolve the way the worker's do: a `credentials` provider name (`ecs`, `instance`) or a callable takes precedence over a `key`/`secret` pair, matching Laravel's own SQS connector. The snapshot client authenticates as the same principal as the worker.

Cloud runs the workers, so keep using its worker configuration. [`queue-insights:work`](09-running-workers.md) is for hosts that supervise their own `queue:work` processes.

## Vapor

### Horizon configured but not running

The idiomatic Vapor setup keeps `config/horizon.php` around while jobs actually run on SQS, with Horizon's service provider excluded (`extra.laravel.dont-discover` plus conditional registration). Supervisor auto-discovery is gated on that provider being loaded, so those supervisor queues are skipped rather than rendered as rows that would never receive a snapshot.

If you want the config-derived rows anyway, set `QUEUE_INSIGHTS_HORIZON_AUTODISCOVER=force`. The dashboard then shows a "Horizon not running" banner so nobody reads empty supervisor rows as a healthy state. See [Horizon auto-discovery](12-horizon.md).

### Queue URLs in `failed_jobs`

`failed_jobs.queue` holds the full SQS URL on Vapor rather than a bare name. Failed-row rendering, queue filtering, and deep-linked scopes all resolve it back to the canonical key, so a failed job on `https://sqs.eu-west-1.amazonaws.com/…/staging_default` sits on the same `staging_default` row as everything else.

### Cold starts and the scheduler

A Vapor scheduler tick is an EventBridge event, and a cold start can push the `Starting` event past the minute it belonged to. Two settings absorb the drift:

- `scheduler.sweeper.drift_seconds` (default 90), how late a `Starting` may arrive and still count.
- `scheduler.sweeper.min_consecutive_misses` (default 2), how many expected fires must pass unobserved before a missed-run alert dispatches.

The defaults tolerate ordinary jitter. Raise `drift_seconds` if cold starts routinely run longer than that. See [Scheduler observability](15-scheduler.md).

### Queue names come from the environment

`SQS_QUEUE`, `SQS_PREFIX`, and `SQS_SUFFIX` define the connection, and the shipped `snapshots[]` default reads `SQS_QUEUE` and `SQS_HIGH_QUEUE`. A dispatch with no explicit `->onQueue()` resolves to the connection's configured default rather than the literal `default`, so producer-side and worker-side records meet on the same key.
