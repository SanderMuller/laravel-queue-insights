# Backward chain lineage — internals + edit points

AI-facing reference for the `chain_lineage` subsystem. End-user docs live in
`README.md` (the `### Chained jobs` section); the design rationale lives in
`internal/specs/backward-chain-lineage.md`. Read this **before** touching any
of the listeners or support classes listed below.

## What it does

Surfaces **who dispatched this job** for any link in a `Bus::chain([...])`.
The forward direction (where the chain is going) was already captured per-row;
this subsystem captures the backward direction so the failed-job markdown
export and the modal can answer "which job ran *before* this failure" without
inspecting application code.

## Pipeline (do NOT reorder these listeners)

```
JobProcessing(parent) ──► push qi:chain-claim:{conn}:{queue}:{nextClass}:{fp} (LPUSH)
                          [happens BEFORE $job->fire(), which dispatches the child]
                          [Phase 0 ordering test locks this assumption]

JobQueued(child)      ──► RPOP qi:chain-claim:{conn}:{queue}:{ownClass}:{tailFp}
                          on hit  ► SETEX qi:lineage:{child-uuid} = parent-uuid

JobProcessing(child)  ──► HSET pending:{uuid} parent_uuid (in-flight modal)

JobProcessed(child)   ──► XADD completed-stream entry includes parent_uuid field
                          DEL  qi:lineage:{child-uuid}     (durable copy lives on the row)

JobFailed(child)      ──► (no copy — qi:lineage:{uuid} survives at 7d TTL,
                          RowEnricher::failed reads it directly per page)

JobProcessed/Failed(parent) ──► SETEX qi:class:{uuid} = class
                                (uuid → class index; hydrates parent_uuid → label)
```

## Key catalogue

| Key | Writer | Reader | TTL | Purpose |
|---|---|---|---|---|
| `qi:chain-claim:{conn}:{queue}:{nextClass}:{tailFp}` | `RecordJobProcessing` (LPUSH via Lua) | `RecordJobQueued` (RPOP) | `chain_lineage.claim_ttl_seconds` (60 s) | Per-shape FIFO list of parent UUIDs awaiting attribution |
| `qi:lineage:{child-uuid}` | `RecordJobQueued` on claim hit | `RowEnricher::failed`, `RecordJobProcessing` (copy to pending), `RecordJobProcessed` (copy to stream then `forgetLineage`) | `chain_lineage.lineage_ttl_seconds` (7 d) | Interim child→parent pointer; durable for failed-row reads |
| `qi:class:{uuid}` | `RecordJobProcessed`, `RecordJobFailed` | `ParentClassResolver::resolve(Many)` | `chain_lineage.lineage_ttl_seconds` (7 d) | uuid → class label; used to render `Parent: {uuid} (Class)` |
| `parent_uuid` field on the completed-stream XADD | `RecordJobProcessed` (copies from `qi:lineage:{uuid}`) | `RowEnricher::completed` | stream retention | Hot-path read with no Redis hit |

## Touchpoints — files that own this subsystem

- `src/Listeners/RecordJobProcessing.php`
  - `pushChainClaim()` — write side. Decodes parent payload via
    `SerializedCommandReader::extractChainContext`, builds the key, LPUSHes the
    parent UUID through `LuaScripts::pushChainClaim()` (atomic LPUSH+EXPIRE).
  - `copyLineageToPending()` — child's read side. Reads `qi:lineage:{uuid}`,
    HSETs `parent_uuid` onto the pending hash. Skipped when pending tracking
    is disabled.
- `src/Listeners/RecordJobQueued.php`
  - `resolveChainLineage()` — RPOPs unconditionally on every `JobQueued`.
    Phase 0 finding #3 confirms `chainConnection`/`chainQueue` are stripped
    by `SerializesModels::__serialize`, so they cannot be used as a "chained
    child" gate. Root jobs miss harmlessly. Cross-shape collision is the
    only false-positive surface — bounded by `(connection, queue, class,
    tail-classes)` shape equality.
  - `extractTailClasses()` — fail-closed; first malformed `chained` entry
    returns null and skips the lookup, so the read-side never collides on a
    partially-parsed parent fingerprint.
- `src/Listeners/RecordJobProcessed.php`
  - `resolveParentUuid()` — copies `qi:lineage:{uuid}` into the stream entry
    and forgets the interim hash. Idempotent: stream entry is appended once.
  - `qi:class:{uuid}` SETEX block — writes the parent-class index used by
    `ParentClassResolver`.
- `src/Listeners/RecordJobFailed.php`
  - `qi:class:{uuid}` SETEX block. Deliberately does NOT touch
    `qi:lineage:{uuid}` — the interim hash's 7-day TTL matches failed-row
    retention and `RowEnricher::failed` reads it directly via batched MGET.
- `src/Support/ChainLineageClaim.php` — pure key + fingerprint builder.
  No I/O. `fingerprint([])` is the last-link case (sha1 of `'[]'`).
- `src/Support/ChainLineageStore.php` — Redis wrapper. Uses
  `chain_lineage.redis_connection` (override) or falls back to
  `redis_connection`.
- `src/Support/ParentClassResolver.php` — uuid → class lookup. `resolveMany`
  batches via MGET so paged failed/completed lists hydrate in one round-trip.
- `src/Support/RowEnricher.php`
  - `completed()` — reads `parent_uuid` straight off the stream entry, then
    hydrates `parent_class` via batched `ParentClassResolver::resolveMany`.
  - `failed()` — `lineageMany()` MGETs `qi:lineage:{uuid}` for every row,
    then resolves classes the same way.
- `resources/views/partials/parent-lineage-row.blade.php` — the `↰ From`
  block. Caller passes a unique `copyId` (DOM id is collision-prone across
  modals).
- `resources/views/components/details-modal.blade.php` and
  `resources/views/components/failed-modal.blade.php` — both `@include` the
  partial above the existing `Chain` block. The failed-modal markdown
  export builder gains the `**Parent:** \`uuid\` (class)` line.
- `src/Support/ConfigValidator.php::validateChainLineage` — wired into the
  service provider's boot path. Type-checks the toggle, the redis_connection
  override (when non-null), and both TTLs.
- `src/Support/Lua/PushChainClaim.lua` — atomic LPUSH+EXPIRE. The
  `LuaScripts::pushChainClaim()` accessor caches the file content per process.

## Behavioural rules — DO NOT VIOLATE

1. **The write side runs at `JobProcessing`, NOT `JobProcessed`.** The child's
   `JobQueued` fires synchronously inside the parent's `fire()` window —
   pushing the ticket at `JobProcessed` is too late. Phase 0 ordering test
   locks this; if it ever fails after a Laravel upgrade, the design must
   move (probably to `JobQueueing` on the parent) before this feature can
   ship on that version.
2. **Never overwrite a non-null `parent_uuid` with null on the durable
   record.** The retry path can re-fire `JobQueued` with a payload that
   yields no chain extraction; `resolveChainLineage` returns early in that
   case so the existing `qi:lineage:{uuid}` is preserved.
3. **List semantics, not single-key SETEX.** Two parents with identical
   shape concurrently in flight would otherwise overwrite each other's
   ticket. LPUSH+RPOP bounds the worst case to "FIFO order across
   identical-shape concurrent chains" — still ambiguous but no overwrite.
4. **Encrypted parents are silently no-op.** `extractChainContext` returns
   null for `ShouldBeEncrypted` payloads; both write and read sides skip.
   Document this if a host's chains rely on encryption.
5. **`chainConnection`/`chainQueue` are NOT the lineage signal.** Phase 0
   finding #3: `SerializesModels::__serialize` strips properties whose
   value equals their declared default. For default `Bus::chain()` usage
   both fields are null, both are stripped, both are unusable. The read
   side's gate is "always RPOP".
6. **`qi:class:{uuid}` is best-effort.** The class label drops past
   `lineage_ttl_seconds`. Don't add a fallback that scans the completed
   stream — the side-key is the contract; aged-out parents render as
   "uuid only".

## Config surface

```php
'chain_lineage' => [
    'enabled' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE', true),
    'redis_connection' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE_REDIS'),
    'claim_ttl_seconds' => 60,
    'lineage_ttl_seconds' => 604800,
],
```

When `enabled = false` every entry point in this subsystem short-circuits at
the listener level — zero Redis writes, zero overhead. Verified by the
`feature flag off short-circuits before any cache write` test.

## Non-goals

Click-through to parent modal, cross-worker exact attribution, cycle traversal protection. See `internal/specs/backward-chain-lineage.md` for residuals + Phase 4 follow-ups.
