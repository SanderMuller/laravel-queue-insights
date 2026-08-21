# Queue-Name Canonicalisation — Logical vs Physical Names

How one SQS-backed queue's two names (`stats` and `stats-{suffix}`) collapse
onto a single canonical key, and why every runtime-sourced queue value has to
go through the connection-aware entry point.

Sibling of [`connection-aliases.md`](connection-aliases.md): that doc owns the
**connection** half of a key, this one owns the **queue** half. End-user
counterpart: `docs/15-vapor-and-cloud.md`.

## Touchpoints

- `src/Support/SqsQueueName.php` — `suffixFor($connection)`, `physical($queue, $suffix)`, `logical($queue, $suffix)`. Stateless, config-read only.
- `src/Support/CanonicalQueueKey.php` — `from()` (shape only), `forConnection()` (shape + suffix strip), `fromOrDefault()` (delegates to `forConnection`).
- `src/Drivers/QueueSnapshotDriverFactory.php` — `makeCloud()` unwraps `queue.connections.{name}.connection`; `sqsFromConfig()` passes `prefix` / `suffix` to the driver and resolves a `credentials` provider string.
- `src/Drivers/SqsSnapshotDriver.php` — `$prefix` / `$suffix` constructor args; `resolveUrl()` assembles the URL locally when a prefix is configured; `canonicalKey()` uses `forConnection`.
- `src/Support/RowEnricher.php` (`failed()`), `src/Support/QueueScopeKey.php` (`decompose()`), `src/Http/Livewire/QueueInsightsDashboard.php` (`selectQueue()`), `src/QueueInsights.php` (`applyFailedJobFilters` + `failedQueueCandidates`) — the non-listener sites that see raw queue values.
- `src/Console/QueueInsightsPurgePendingCommand.php` — deliberately still on `from()`.

## The two names

A queue connection may carry a `suffix` (`SQS_SUFFIX` on Vapor; a
per-environment suffix on Laravel Cloud's managed connection). Laravel appends
it when building the queue URL (`Illuminate\Queue\SqsQueue::suffixQueue()`), so
one queue answers to:

| | value | who sees it |
|---|---|---|
| logical | `stats` | `JobQueued::$queue`, `queue.connections.*.queue`, `snapshots[]`, operators |
| physical | `stats-abc123` | the queue URL — `SqsJob::getQueue()`, `failed_jobs.queue` |

**The package keys the logical name.** Same choice Laravel Cloud makes for its
own UI (`Illuminate\Foundation\Cloud\Queue::normalizeQueue()` strips prefix and
suffix).

## The bug this fixes

The producer (`RecordJobQueued`) only ever sees the logical name; the worker
listeners read the queue off the job, which is the URL. Keyed as-is, the two
never meet:

- `markInFlight`'s `zrem` on `pending-zset:{c}:stats-abc123` never clears the
  producer's entry under `pending-zset:{c}:stats` → phantom pending rows for
  the full `pending.ttl_seconds` window, `oldest_pending` firing throughout.
- Dashboard renders one queue as two rows: `stats` (queued counts) and
  `stats-abc123` (processed / in-flight / snapshot counts).

Pre-existed for any plain SQS connection with a suffix; Laravel Cloud made it
the default reality, since its managed connection always sets one.

## Laravel Cloud's config shape

`cloud` is a wrapper, not a transport — `Illuminate\Foundation\Cloud::
bootManagedQueues()` registers a connector delegating to `SqsConnector`:

```
queue.connections.cloud
  driver      => 'cloud'
  queue       => 'default'          # logical default
  queues      => [...]              # every managed queue on the environment
  agent       => ['enabled' => …]
  connection  =>                    # a complete SQS connection config
    driver, prefix, suffix, queue, region, credentials, after_commit, overflow
```

`makeCloud()` unwraps one level and builds the SQS driver from `connection`.
`queues` / `agent` / `overflow` describe delivery, not depth — not read.

## Behavioural rules

1. **Runtime queue value → `forConnection()`. Config / stored key → `from()`.**
   A value that came off a job, a `failed_jobs` row, or a URL may be physical.
   A value read from `snapshots[]` or from a key the package itself wrote is
   already logical.
2. **`fromOrDefault()` handles the listeners.** All four `Record*` listeners go
   through it, so the suffix strip is one change, not four call sites.
3. **`suffixFor()` reads both shapes** — `queue.connections.{c}.connection.suffix`
   (Cloud's nesting) before `queue.connections.{c}.suffix` (plain SQS).
4. **The suffix lookup is alias-aware.** Callers hand over the canonical
   (aliased) connection; when it is not a `queue.connections.*` key,
   `suffixFor()` walks `connection_aliases` backwards to the sources that map
   onto it. Without that, an aliased suffixed connection resolves no suffix and
   the producer / worker split returns.
5. **`prefix` short-circuits `GetQueueUrl`.** A configured prefix IS the URL
   base, so `resolveUrl()` assembles `{prefix}/{physical}` locally — no API
   round-trip, no Redis URL-cache entry. Without a prefix, the cache key uses
   the physical name so a suffixed and unsuffixed connection can't collide.
6. **`failed_jobs` filtering needs both spellings.** The stored value ends in
   the physical name while the filter carries the logical one, so
   `failedQueueCandidates()` expands to both before the `IN` / `LIKE` pass.

## What NOT to do

- **Do not** call `CanonicalQueueKey::from()` on a value that came off a job or
  a `failed_jobs` row — it keeps the suffix and re-splits the key.
- **Do not** move the suffix strip into `from()` itself. It has no connection
  to look up, and the config/stored-key callers must stay shape-only.
- **Do not** switch `queue-insights:purge-pending` to `forConnection()`. Its
  argument names a key **as stored**, and orphans are exactly the keys that
  don't match what current code derives — stripping would retarget it away
  from them.
- **Do not** put the suffix after `.fifo`. `physical()` mirrors
  `SqsQueue::suffixQueue()`: `orders.fifo` → `orders-{suffix}.fifo`. AWS
  rejects anything else.
- **Do not** add a `cloud` snapshot-driver class. `cloud` unwraps to its nested
  driver; a wrapper existed briefly and was deleted once keys went logical.

## Migration

No migration command. Keys written under physical names age out via
`pending.ttl_seconds` (24h default) and the metric TTLs, or an operator clears
them now with `queue-insights:purge-pending {connection} {physical-name}`.

For hosts already running a suffixed SQS connection this is behaviour-changing:
two dashboard rows collapse into one, and Prometheus `queue` label values move
from physical to logical. Pinned dashboards / alert rules need updating —
call it out in the release notes.

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| One queue shows as two rows, one with depth only | A raw queue value reached a key write via `from()` instead of `forConnection()` | Trace the site; route it through `forConnection` |
| `oldest_pending` fires on jobs that finished | Producer / worker key split — as above | Same |
| `unknown queue driver` warning on Laravel Cloud | `queue.connections.cloud.connection` missing, or its `driver` is not `sqs` | Check the injected config; a non-SQS nested driver is unsupported by design |
| Snapshots silently dead on Cloud after upgrading | A leftover `driver_overrides.cloud => 'null'` still wins over the built-in path | Remove the override |
| `GetQueueUrl` called on a Cloud connection | `prefix` absent from the nested config | Expected fallback, not a bug — the physical name is still resolved |
