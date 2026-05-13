# Connection Aliases — Per-Connection Key Canonicalisation

How operator-declared `queue-insights.connection_aliases` collapses
producer / worker / dashboard reads onto a single canonical connection
name, fixing the pending-row-invisible drift bug.

## Touchpoints

- `src/Support/ConnectionAlias.php` — `canonical(string): string` resolver. Stateless. One hash lookup.
- `src/Support/KeyPrefix.php` — `classKey($prefix, $class, $connection)` and `queueKey($prefix, $connection, $queue)` apply canonicalisation **inside** the helpers.
- `src/Support/ConfiguredQueueList.php` — `push()` canonicalises connection before scope filter + dedup-seen set.
- `src/Listeners/RecordJobQueued.php` (producer) — `$connection = ConnectionAlias::canonical((string) $event->connectionName)` at top of `handle()`.
- `src/Listeners/RecordJobProcessing.php`, `RecordJobProcessed.php`, `RecordJobFailed.php` (worker) — same canonicalisation pattern.
- `src/Support/ConfigValidator::validateConnectionAliases()` — wired into `validateConfig` BEFORE `validateSnapshots`.
- `config/queue-insights.php` — `connection_aliases` block.

## The bug

`JobQueued::$connectionName` is the **dispatcher**'s queue connection
name; `JobProcessing/Processed/Failed::$connectionName` is the
**worker**'s. When both Laravel connections point at the same physical
queue store (e.g. `redis` for dispatchers, `redis-staging` for Horizon
workers, both with `database: 0`), the producer writes
`pending-zset:redis:foo` and the worker tries to `ZREM` from
`pending-zset:redis-staging:foo`. The keys never meet — pending row is
orphaned until TTL, and the dashboard panel scoped to `redis-staging`
shows zero pending for a queue Horizon is actively draining.

Same drift affects every connection-keyed keyspace: `pending-zset`,
`inflight-zset`, `wait`, `depth/inflight/delayed` history,
`live:depth/inflight/delayed`, `samples:depth`, `snapshot:error`,
`snapshot-errors-total`, `classes:{c}`, `completed:connection:{c}`,
`processed:{class}:{c}:{bucket}`, `failed:{class}:{c}:{bucket}`,
`processed-total:{class}:{c}`, `failed-total:{class}:{c}`,
`duration:{class}:{c}`, `duration:samples:{class}:{c}`,
`last_run:{class}:{c}`.

## Operator config

```php
'connection_aliases' => [
    'redis' => 'redis-staging',
    'redis-staging' => 'redis-staging',
],
```

## Validator rules

- Keys + values are non-empty strings.
- Identity mappings (`A => A`) are allowed.
- Transitive chains (`A => B, B => C, B !== C`) are **rejected**. Single-hop resolution; operators flatten manually.
- Mutual cycles (`A => B, B => A`) fall under the chain rule above.

## Behavioural rules

1. **Apply at the helper boundary, not every caller.** `KeyPrefix::classKey` and `KeyPrefix::queueKey` are the single canonicalisation points for the class+connection and queue-scoped key families. Caller-level wrapping is brittle — Codex flagged 8 missed sites before this helper-level boundary was chosen.
2. **Canonicalise at listener top.** Each `Record*` listener sets `$connection = ConnectionAlias::canonical(...)` once; everything downstream (key writes, `pending:{uuid}` hash `connection` field, chain-lineage claim keys) uses the canonical value.
3. **Dashboard reads via `configuredQueues()` see canonical names.** `ConfiguredQueueList::push` canonicalises connection on insertion + dedup. Snapshot collisions on the canonical key are rejected by `validateSnapshots` at boot.
4. **`pending:{uuid}` hash stores canonical `connection`.** New rows post-fix carry the canonical value; legacy rows age out via TTL (`pending.ttl_seconds`, default 24h).

## Migration

Phase 1 ships **no migration command**. Existing per-connection zset
entries written under the pre-alias names age out via `pending.ttl_seconds`
TTL (default 86400 = 24h). Dashboard shows correct data within one TTL
window after the operator publishes the alias map.

Phase 3 will ship `queue-insights:migrate-aliases` for hosts that don't
want to wait — **NOT online-safe**, requires quiesced dispatch + drained
workers. `ZUNIONSTORE` is **not** used (default SUM aggregate corrupts
timestamp scores); the command iterates `ZRANGE WITHSCORES` + `ZADD` to
preserve scores.

## What NOT to do

- **Do not** wrap every caller of `KeyPrefix::make("...:{$conn}:...")` with `ConnectionAlias::canonical`. Use `KeyPrefix::queueKey` instead — the canonicalisation lives inside the helper.
- **Do not** add transitive resolution to `ConnectionAlias::canonical`. Single-hop is intentional; the validator rejects chains so the resolver stays a one-line hash lookup.
- **Do not** rewrite worker-side `pending:{uuid}` hash `connection` fields on each event. Producer-side write at enqueue is sufficient; legacy rows age out.
- **Do not** run `ZUNIONSTORE` to merge zsets — default `AGGREGATE SUM` corrupts unix-timestamp scores.

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| Pending rows still invisible after publishing aliases | A worker is still writing to a connection name that's neither a key nor a value in the alias map | Either add the missing entry, or check the listener-side `pending:{uuid}` hash `connection` field to confirm which name the worker is using. The path-scoped URL canonicalises automatically — legacy `/queue-insights/redis` resolves to canonical scope. |
| Prometheus alert series silent after rollout | `connection` label renamed to canonical alias | Add a Prometheus relabel rule bridging the old name to the new |
| Snapshot validator throws `collision` error at boot | Two snapshot entries collide post-alias canonicalisation | Drop the duplicate snapshot entry — they were redundant |
| `transitive chain rejected` at boot | Operator declared `A => B` and `B => C` | Flatten manually: `A => C, B => C` |
