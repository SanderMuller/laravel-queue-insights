# Prometheus — internals + extension points

AI-facing reference for the Prometheus exposition subsystem. End-user
docs live in `README.md` (the `### Prometheus` section); the design
rationale + open questions live in `internal/specs/prometheus-export.md`.
Read this **before** touching any of the files listed below.

## What it does

Exposes queue-insights state on a scrapeable `/metrics` endpoint
(Prometheus 0.0.4 + OpenMetrics 1.0.0 via `Accept` negotiation), plus
an opt-in `queue-insights:prometheus-push` artisan command for short-
lived processes. Mostly a translation layer over Redis state the
package already owns; the listeners ship 4 new monotonic INCR keys
(`processed-total:*`, `failed-total:*`, plus the per-connection
variants) and the snapshot command ships 1 new monotonic INCR key
(`snapshot-errors-total:*`).

## Touchpoints — files that own this subsystem

- `src/Prometheus/Registry.php` — collect-render orchestrator. Bound
  with `bind()` (NOT `singleton`) per spec §8 — Octane-safety mirrors
  `ActiveIssuesProvider`. Per-flavour memoise (`text` / `openmetrics`)
  + Redis cache `prom:cache:rendered:{flavour}` with TTL =
  `cache_ttl_seconds`. Times the inner collect cycle and feeds duration
  to `Collectors\ExporterSelfCollector::record`.
- `src/Prometheus/Renderer.php` — text-format + OpenMetrics renderer.
  Sample order = registration order; labels alphabetised; `# EOF`
  appended for OpenMetrics only. Rejects invalid metric names at
  render time so a typo'd collector fails loudly.
- `src/Prometheus/Exposition/{Sample,MetricFamily,EscapeLabel}.php` —
  pure DTOs + the label-value escape helper.
- `src/Prometheus/Collector.php` — `isEnabled() + collect(): list<MetricFamily>`.
  Returns a list (deviation from spec §6 example) so
  `DurationAggregateCollector` can emit count/sum/max from one HMGET.
- `src/Prometheus/Collectors/PerClassMonotonicCounterCollector.php` —
  abstract base shared by `JobsProcessedCollector` and
  `JobsFailedCollector`. Subclasses declare `keyShape()`,
  `metricName()`, `helpText()`, `metricToggleKey()`; the base owns
  the MGET-chunk loop.
- `src/Prometheus/Collectors/*Collector.php` — one file per metric
  family (or the trio in DurationAggregateCollector's case). Per-metric
  `isEnabled()` reads `prometheus.metrics.{key}`.
- `src/Support/SnapshotPairs.php` — single source of truth for
  iterating `(connection, canonicalQueue)` pairs from the `snapshots`
  config. All queue-scoped collectors go through it.
- `src/Support/ZsetHead.php` — driver-agnostic decoder for
  `WITHSCORES`-flagged `ZRANGE` heads. Shared with
  `Alerts\Detectors\StuckInFlightDetector` and `OldestPendingDetector`
  so the `member => score` shape can't drift across drivers.
- `src/Prometheus/ClassFilter.php` — `MODE_ALLOW_ALL` /
  `MODE_ALLOW_LIST` / `MODE_TOP_N_BY_RECENCY`. Bound `scoped` so the
  per-(mode, connection) memoise survives one request — three
  class-scoped collectors share a single `ZRANGE` per scrape.
  `allow_list` `array_unique`s the configured FQCNs to rule out
  duplicate Prometheus series on a typo'd config.
- `src/Prometheus/MetricsController.php` — single-action controller.
  `Accept` header drives flavour selection.
- `src/Prometheus/PrometheusAuthMiddleware.php` — fail-closed default:
  bearer token (`hash_equals`) → IP CIDR (`IpUtils::checkIp`) → 403.
- `src/Prometheus/PushGateway/Pusher.php` — Pushgateway client. Builds
  `/metrics/job/{job}/instance/{instance}` URLs with `rawurlencode`,
  forwards basic-auth credentials embedded in the URL.
- `src/Console/QueueInsightsPrometheusPushCommand.php` — one-shot
  collect + push. Fail-closed on missing `pushgateway.instance`
  unless `--accept-shared-grouping` is passed (see §Behavioural
  rules). Catches `InvalidArgumentException` separately from
  `Throwable` so config errors exit `INVALID` (2) and HTTP failures
  exit `FAILURE` (1).
- `routes/prometheus.php` — loaded **independently** of
  `routes/web.php` so headless replicas (dashboard.enabled = false)
  can still expose `/metrics`. `prometheus.middleware = null` falls
  back to the package gate; an explicit `[]` exposes `/metrics` raw
  for hosts behind outer infra auth. Route name `queue-insights.metrics`.
- `src/Support/PrometheusConfigValidator.php` — extracted from
  `ConfigValidator` (mirrors the `validateAlerts → AlertsConfigValidator`
  pattern) to keep cognitive complexity under PHPStan's ceiling.
  Wired in the provider's boot path. Rejects malformed CIDR allow_ips,
  unknown `class_filter.mode` (driven by `ClassFilter::MODE_*`
  constants — single source), non-positive `top_n`, negative
  `cache_ttl_seconds`.
- `src/Listeners/RecordJobProcessed.php::writeProcessedMonotonic` —
  two `INCR` writes (aggregate + per-connection) via
  `KeyPrefix::classKey('processed-total', ...)`, each followed by a
  refreshing 30-day `EXPIRE`. TTL-on-write replaces the prior
  prune-DEL pattern (race-free).
- `src/Listeners/RecordJobFailed.php::writeFailedMonotonic` — symmetric.
- `src/Console/QueueInsightsSnapshotCommand.php::recordError` —
  extends the existing 10-min boolean write with an
  `INCR snapshot-errors-total:{c}:{q}` (no TTL).
- `src/Console/QueueInsightsSnapshotCommand.php::pruneClasses` —
  ZREMRANGEBYSCORE-only on the `classes` / `classes:{conn}` zsets.
  Does NOT touch `processed-total:*` / `failed-total:*`; those age
  out via the listener-side EXPIRE.

## Key catalogue (NEW keys this subsystem owns)

| Key | Writer | Reader | TTL |
|---|---|---|---|
| `processed-total:{class}` | `RecordJobProcessed::writeProcessedMonotonic` | unused by collectors (kept for symmetry + future global rollups) | 30 d, refreshed per INCR |
| `processed-total:{class}:{connection}` | same | `JobsProcessedCollector` (MGET via base class) | 30 d, refreshed per INCR |
| `failed-total:{class}` | `RecordJobFailed::writeFailedMonotonic` | unused (parity) | 30 d, refreshed per INCR |
| `failed-total:{class}:{connection}` | same | `JobsFailedCollector` (MGET via base class) | 30 d, refreshed per INCR |
| `snapshot-errors-total:{c}:{q}` | `QueueInsightsSnapshotCommand::recordError` | `SnapshotErrorsCollector` (GET) | none (snapshot-pair count is bounded by `snapshots` config) |
| `prom:cache:rendered:{flavour}` | `Registry::writeCache` | `Registry::readCache` | `prometheus.cache_ttl_seconds` (5 s default) |

Reused, NOT re-written: `live:depth:*`, `inflight-zset:*`,
`pending-zset:*`, `duration:{class}:{connection}` hash,
`classes:{connection}` zset.

## Behavioural rules — DO NOT VIOLATE

1. **`Registry` is `bind()`, never `singleton()`.** Per-flavour render
   memoise leaks across requests under Octane otherwise. Mirrors
   `ActiveIssuesProvider`. The Redis cache is the cross-request bound.
2. **`ExporterSelfCollector` is `scoped()`.** Registry instances within
   a request share it so the duration sample survives the gap between
   `Registry::collect`'s timing block and the self-collector's own
   `collect()` call. Cross-request leakage is harmless — the gauge is
   "previous cycle" by spec.
3. **`ClassFilter` is `scoped()`.** Per-(mode, connection) memoise
   shares the underlying ZRANGE across the three class-scoped
   collectors in one scrape. Each request gets a fresh memoise.
4. **Collector errors do NOT poison the scrape.** `Registry::collect`
   wraps each collector in try/catch + log warning. One broken
   collector skips its families; the rest render.
5. **`PrometheusAuthMiddleware` is fail-closed.** No silent open
   default. Hosts that override `prometheus.middleware` with an
   explicit array (including `[]`) opt out of this entirely and own
   their own gate.
6. **Snapshot age omits the metric when the key is absent.** Don't
   clamp to 0 — that makes a dead snapshot loop look fresh, which is
   exactly the failure mode the metric exists to catch. Pair with
   `snapshot_alive` for boolean liveness.
7. **No histograms in v1.** The `duration:samples:{class}:{connection}`
   capped LIST cannot back a Prometheus histogram (rotates non-
   monotonically). Hosts who need p95/p99 in Prometheus run their own
   client-side histogram listener or read p95 off the dashboard.
8. **Monotonic counters age out via per-INCR EXPIRE, NOT via the
   snapshot prune.** Earlier drafts pruned `processed-total:*` /
   `failed-total:*` alongside the `classes` zset eviction — that path
   raced with concurrent listener INCRs and could DEL an active class's
   counter mid-tick, breaking Prometheus monotonicity. The listener
   writes refresh a 30-day EXPIRE on every INCR; dormant classes age
   out without any prune logic.
9. **Push command fails-closed on missing `pushgateway.instance`.**
   Operators MUST acknowledge shared-grouping risk via
   `--accept-shared-grouping` or set `instance` per-replica. Silent
   overwrites between clustered pushers are a debugging nightmare.
10. **`class_filter` defaults to `allow_list` with empty list.** Per-
    class metrics are off-by-default — adoption is opt-in. Hosts who
    want all classes set `mode = allow_all` and accept the cardinality
    bill. `allow_list` deduplicates configured FQCNs so a typo'd
    duplicate entry can't produce duplicate Prometheus series.
11. **Use `KeyPrefix::classKey($shape, $class, $connection?)` for
    every monotonic-counter key.** Listener writers and collector
    readers go through the same helper so the key shape can't drift
    on either side. Same guarantee documented on the helper itself.

## What NOT to do

- Don't add a host-side fluent API (`Prometheus::addGauge(...)`)
  modelled on `spatie/laravel-prometheus`. Domain is fixed (queue
  observability); custom metrics belong in the host's own exporter.
  Spec §7 line 243.
- Don't pull in `promphp/prometheus_client_php`. The 80% of its API
  we don't use would still be a public-API dep. The ~150 LOC renderer
  is the cost. If a future phase ships a true histogram, revisit
  then for its battle-tested bucketing.
- Don't write `prom:*` keys outside `KeyPrefix::make()`. Every
  exporter-owned key goes through the configured prefix, same as
  every other package surface.
- Don't add per-class metrics for `slow_p95` (or any other percentile)
  from the sample LIST. The LIST rotates; resolved Q3 in the spec.
- Don't expose the renderer or DTOs as host-extension points. Marked
  `@internal` — collectors are the supported extension boundary.
  Custom collectors implement `Prometheus\Collector` (or extend
  `PerClassMonotonicCounterCollector` if they share the per-class
  MGET shape).
- Don't add `prometheus.metrics.exporter_self` toggle for the
  self-collector — operational value is highest when always-on.
  Adding it later if a host actually asks is cheap.
- Don't reach for a separate `prune-prom-counters` artisan command.
  TTL-on-write in the listener is the only sweep mechanism this
  subsystem needs.

## Config surface

```php
'prometheus' => [
    'enabled' => env('QUEUE_INSIGHTS_PROMETHEUS_ENABLED', false),
    'path' => 'metrics',
    'middleware' => null,                              // null → use package default; [] → expose raw
    'token' => env('QUEUE_INSIGHTS_PROMETHEUS_TOKEN'),
    'allow_ips' => [],                                 // CIDR strings
    'class_filter' => [
        'mode' => 'allow_list',                        // | allow_all | top_n_by_recency
        'classes' => [],
        'top_n' => 50,
    ],
    'metrics' => [ /* per-family on/off toggles */ ],
    'cache_ttl_seconds' => 5,                          // 0 disables both layers
    'pushgateway' => [
        'url' => env('QUEUE_INSIGHTS_PUSHGATEWAY_URL'),
        'job' => env('QUEUE_INSIGHTS_PUSHGATEWAY_JOB', 'laravel-queue-insights'),
        'instance' => env('QUEUE_INSIGHTS_PUSHGATEWAY_INSTANCE'),
    ],
],
```

When `enabled = false` the route + middleware alias are not
registered; collectors are inert. The listener INCRs (`processed-total:*`
etc.) ship anyway — they're harmless when no scrape happens and
removing the gate would couple the listener hot path to a config flag.
