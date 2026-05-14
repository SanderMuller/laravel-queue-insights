# Redis Cluster Support — Single-Slot Pinning

How the package runs against cluster-mode Redis: hash-tag the prefix so
every key shares one slot, and route pipelines through an eager fallback
because `RedisCluster` has no `pipeline()`.

## Touchpoints

- `src/Support/KeyPrefix.php` — `make()` wraps the prefix in a Redis hash tag `{…}` when `redis_cluster` is on. `classKey()` / `queueKey()` inherit it (both funnel through `make()`).
- `src/Support/RedisPipeline.php` — `run()` routes `PhpRedisClusterConnection` / `PredisClusterConnection` to the eager fallback instead of `Connection::pipeline()`.
- `src/Support/EagerCommandCollector.php` — the eager pipeline stand-in: executes each queued command immediately, collects replies in order.
- `src/Support/RedisEval.php` — unchanged; `eval` already routes correctly on a cluster connection (it picks the slot from the KEYS, which all share the hash tag).
- `config/queue-insights.php` — `redis_cluster` flag.
- `.github/workflows/run-tests.yml` — the `test-cluster` job (`grokzen/redis-cluster` service, `--group=cluster`).
- `tests/Feature/Cluster/ClusterSupportTest.php` — `cluster`-group integration tests; `tests/TestCase.php` + `tests/Support/RedisAvailability.php` wire up the optional `cluster` connection from `REDIS_CLUSTER_HOST`.

## The problem

Redis Cluster rejects any **single command that references multiple keys
in different hash slots** with `CROSSSLOT`. The package leans on multi-key
atomicity throughout:

- 10 Lua scripts with 2–3 `KEYS` each (`MarkInFlight`, `IncrPairWithExpire`,
  `DurationPair`, `SamplesPair`, `SetexPair`, `ClassesRoster`,
  `BatchClaimConnection`, `RewriteScheduleSnapshot`, …).
- ~8 raw `MGET` fan-outs across per-uuid / per-class key lists
  (`WaitTimeMetrics`, `BatchReader`, `RowEnricher`, `ParentClassResolver`,
  `HourlyBucketReader`, `ChainLineageStore`, `QueueInsights`,
  `PerClassMonotonicCounterCollector`). (`HMGET` is single-key — no risk.)
- One raw `RENAME` (`QueueInsightsPurgePendingCommand`).
- Pipelines via `RedisPipeline::run` (read paths).

Every key in all of these is built through `KeyPrefix::make()`, so the hash
tag co-locates them — but that invariant is load-bearing: a key built any
other way would silently re-introduce CROSSSLOT. The `cluster` test group
exercises a Lua script, a pipeline, a `RENAME`, and an `MGET` against a
real cluster to keep that honest.

Separately, phpredis's `RedisCluster` client exposes **no `pipeline()`
method**, and `PhpRedisClusterConnection` inherits
`PhpRedisConnection::pipeline()` unchanged — so a pipeline against a
clustered connection fatals with an undefined-method `Error`.

## The fix — single-slot pinning

`redis_cluster = true` makes `KeyPrefix::make()` wrap the prefix in a hash
tag: `qm:staging:` → `{qm:staging:}`. Redis hashes only the braced span,
so **every** package key lands on the same slot. Every multi-key Lua
script + the `RENAME` then operate within one slot — CROSSSLOT-legal.
`RedisPipeline::run` separately detects cluster connections and runs the
callback through `EagerCommandCollector` (N round-trips, each routed by
the cluster client) instead of `pipeline()`.

`key_prefix` is read in exactly one place (`KeyPrefix::make()`) and every
key builder funnels through it — so the hash tag is guaranteed to be the
verbatim leading substring of 100% of package keys. No multi-key op mixes
a package key with a non-package key.

## Operator config

```dotenv
QUEUE_INSIGHTS_REDIS_CLUSTER=true
```

The matching Redis connection must also be a real Laravel cluster
connection (`clusters` block or `options.cluster`) so the client follows
`MOVED`. See `UPGRADING.md`.

## Behavioural rules

1. **The hash tag wraps the whole prefix.** `{` + `$prefix` + `}`. Deterministic, no guessing a "dynamic segment".
2. **Never double-wrap.** If the operator already put a `{…}` in `key_prefix`, `make()` leaves it untouched (`str_contains($prefix, '{')` guard) — a deliberate per-tenant tag is theirs to own.
3. **Pinning is all-or-nothing.** Either every package key shares the tag (correct) or the feature is off. There is no partial mode.
4. **Cluster detection is by connection class**, not config — `RedisPipeline` checks `instanceof PhpRedisClusterConnection|PredisClusterConnection`, checked **before** the `PhpRedisConnection` branch (cluster extends it).

## What NOT to do

- **Do not** try to shard the keyspace across slots. The package's atomic multi-key Lua scripts cannot span nodes — single-slot pinning is the only model that preserves their crash-consistency guarantees.
- **Do not** add per-entity hash tags (e.g. tag by `{connection:queue}`). Scripts like `RecordJobProcessed` legitimately touch keys for different entities (global stream + per-class + per-connection) in one flow — they would land on different slots and break.
- **Do not** flip the hash-tagged prefix on by default. It renames every key — a breaking keyspace change for existing installs. Opt-in only.
- **Do not** point a plain (non-cluster) Laravel connection at a cluster endpoint and rely on the hash tag alone. A plain client does not follow `MOVED`; pinning makes failure deterministic but not absent. The connection must be a real cluster connection.

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| `CROSSSLOT Keys in request don't hash to the same slot` | `redis_cluster` not enabled, or `key_prefix` lost its hash tag | Set `QUEUE_INSIGHTS_REDIS_CLUSTER=true`; confirm `KeyPrefix::make()` output starts with `{…}` |
| `Call to undefined method RedisCluster::pipeline()` | A pipeline reached `Connection::pipeline()` instead of the eager fallback — a new cluster connection class not covered by the `instanceof` check | Add the class to `RedisPipeline::run`'s cluster branch |
| Commands error with `MOVED` | The connection is a plain client against a cluster endpoint | Reconfigure as a Laravel `clusters` connection so the client follows redirects |
| `cluster` test group all skipped in CI | `REDIS_CLUSTER_HOST` not exported / cluster service didn't reach `cluster_state:ok` | Check the `test-cluster` job's service health-check |
