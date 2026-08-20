# Ops runbook

## Console commands

| Command                                             | When to run                                                                                                                                                                                                                                                                                                                           |
|-----------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `queue-insights:snapshot`                           | Auto-registered every minute on Laravel's scheduler when `schedule.enabled=true` (default). Captures depth / in-flight / delayed for every queue in `snapshots[]`. Run manually for one-off captures or when you've opted out of auto-registration.                                                                                   |
| `queue-insights:work`                               | Long-running supervisor that boots one `queue:work` per `snapshots[]` connection. Use when not running Horizon. See [Running workers](08-running-workers.md).                                                                                                                                                                         |
| `queue-insights:purge-pending {connection} {queue}` | One-shot cleanup of orphan pending entries on a single (connection, queue) pair (workers that crashed mid-pickup, raw `Queue::push()` outside Laravel's event flow). Default dry-run; pass `--force` to mutate. Refuses to scrub the live default queue unless `--allow-live-queue` is set. Not online-safe — quiesce dispatch first. |
| `queue-insights:migrate-aliases`                    | One-shot migration after publishing `connection_aliases` — rewrites pending/inflight zsets onto the canonical name without waiting for `pending.ttl_seconds` to drain. Default dry-run; `--force` to mutate. See [Connection aliasing](12-connection-aliasing.md).                                                                    |
| `queue-insights:prometheus-push`                    | One-shot collect + PUT to a Pushgateway, for short-lived workers / CLI scripts. See [Push gateway](13-prometheus.md#push-gateway-short-lived-workers-cli).                                                                                                                                                                            |
| `queue-insights:schedule:list`                      | Print the captured scheduler-task snapshot table. Read-only. Requires `scheduler.enabled`.                                                                                                                                                                                                                                            |
| `queue-insights:schedule:sweep`                     | Detect missed + hung scheduler runs; dispatch their typed events. Auto-registered on Laravel's scheduler with `->everyMinute()->onOneServer()->withoutOverlapping()` when `scheduler.enabled` and `scheduler.sweeper.enabled` are both true. Run manually for one-off sweeps.                                                         |

## Dashboard signals

| Signal                     | Meaning                                                                                                             |
|----------------------------|---------------------------------------------------------------------------------------------------------------------|
| `—` on in-flight / delayed | Driver can't produce the metric (Null / sync), or the live cache expired (>90s since the last successful snapshot). |
| `stale` badge              | No snapshot ran in the last 2 minutes.                                                                              |
| `error` badge              | Last snapshot run failed for this queue. Hover for the error message (10-minute TTL).                               |
| `no snapshot yet`          | The command has never completed successfully against this queue.                                                    |

## Driver-specific quirks

- SQS values are AWS approximations. `GetQueueUrl` is cached for 1h in Redis; the first run per new queue name costs one extra API call.
- Redis reads `LLEN queues:{name}` plus `ZCARD` on `:reserved` and `:delayed`. Matches Laravel's own queue key convention.
- Database depth includes rows whose reservation has expired (crashed workers leave their jobs poppable again). Matches `DatabaseQueue::getNextAvailableJob()` exactly.

## Key-prefix strategies

- Shared Redis (multi-tenant, or multiple apps or envs on the same Redis): keep the default `QUEUE_INSIGHTS_KEY_PREFIX=qm:{APP_ENV}:`. Safe against collision.
- Dedicated Redis: override to `QUEUE_INSIGHTS_KEY_PREFIX=qm:` to drop the env segment and shorten every key.

## Redis Cluster

Queue Insights issues multi-key Lua scripts and pipelines (atomic counter pairs, the pending → in-flight transition, batched dashboard reads). Redis Cluster rejects any multi-key command whose keys span hash slots with `CROSSSLOT` — so on a cluster-mode Redis those writes silently fail (the listeners catch and log; the dashboard reads error).

To run against Redis Cluster:

1. **Set `QUEUE_INSIGHTS_REDIS_CLUSTER=true`.** This wraps `key_prefix` in a Redis hash tag (`{qm:env:}…`), so every key the package writes hashes to a single slot and multi-key ops become CROSSSLOT-legal. If you have already placed your own `{…}` tag in `QUEUE_INSIGHTS_KEY_PREFIX`, it is left as-is.
2. **Configure the matching connection as a real cluster connection** in `config/database.php` (a `clusters` block, or `options.cluster`) so the client follows `MOVED` redirects — a plain connection pointed at a cluster endpoint will not. See [UPGRADING.md](https://github.com/SanderMuller/laravel-queue-insights/blob/main/UPGRADING.md) for a copy-paste `clusters` block.

Trade-off: hash-tag pinning co-locates the entire Queue Insights keyspace on **one** cluster slot — i.e. one node. That is intentional and fine for a bounded observability keyspace (capped streams, TTL'd keys), but it means Queue Insights does not shard across the cluster. If that keyspace is large enough to matter, point `redis_connection` at a standalone (non-cluster) Redis instead.
