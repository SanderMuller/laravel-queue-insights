# Jobs, batches, and chains

The queue tiles tell you a queue is backed up. These sections tell you about the
individual jobs in it: how long one waited before pickup, what's still pending or
delayed, how far a batch has got, what a chain runs next, and where the dispatch
came from.

## Wait time

Wait time is the gap between enqueue and worker pickup. Duration is the gap between worker pickup and completion. They're different numbers, and wait time is the one to look at when depth / in-flight look fine but jobs feel slow.

It shows up in two places:

- Queue rows show a `p50 / p95` Wait column, computed over the most recent 1000 jobs on that queue and refreshed every poll. Shows `—` until 10 samples have accumulated.
- The completed-job and failed-job modals show `wait <human> (NN ms)` next to the Duration row. Shows `—` for jobs queued before the `JobQueued` listener was wired, and for drivers that don't stamp `payload.uuid`.

Capture is automatic. Installing the package wires an `Illuminate\Queue\Events\JobQueued` listener that records the enqueue timestamp, so no host-app config is needed. The cost per job is one Redis `SETEX` at push, plus a `GET` + `ZADD` + `ZREMRANGEBYRANK` + `EXPIRE` chain at worker pickup. Retention: 1h on the per-uuid `pushed:` key, 7d on the per-uuid `wait:` sample, rolling 1000 most-recent on the per-queue ZSET.

A 7-day clock-skew guard rejects any wait sample over that, so a producer host with bad NTP can't poison the percentile pool indefinitely.

## Pending & delayed jobs

Each queue row in the dashboard has a collapsible inspector that shows individual pending and delayed jobs, class FQCN, queued-at humanized, and (for delayed) `runs in <countdown>`. The toggle button shows the tracked count next to the queue's badges; click to expand. The expand state is URL-shareable (`?qopen=connection:queue`).

The Pending tab itself shows three sub-sections (in-flight / pending / delayed). Per-row chips surface live state: amber `running` with a pulsing dot for in-flight rows, indigo `delayed` with a hover tooltip showing total delay + queued/runs timestamps for delayed rows, and an orange `retry N` chip when the worker has picked the job up more than once (`attempts > 1`). The retry stamp is written by the `JobProcessing` listener via `MarkInFlight.lua` and ages out with the pending hash.

The data is **event-captured into Redis**, not peeked from the queue driver. The `JobQueued` listener stamps a per-uuid hash + per-queue sorted set into the package's Redis namespace; `JobProcessing` / `JobProcessed` / `JobFailed` clean up. Driver-agnostic by design, works for SQS, where there's no way to peek individual messages without consuming them, alongside Redis and database queues.

Bounded storage:

- ~500 bytes per pending job (uuid + class FQCN + connection + queue + queued_at + available_at).
- Per-queue cap (`pending.max_per_queue`, default 10000) enforced via `ZREMRANGEBYRANK`, when the cap is hit, the lowest-score (earliest `available_at`) entry is dropped first.
- TTL safety net (`pending.ttl_seconds`, default 86400 = 24h) drops orphans whose cleanup listener never fired (worker crash, raw `Queue::push()` outside Laravel's event flow).

The dashboard compares the tracked count against the snapshot's `depth + delayed`, when they diverge by more than `pending.gap_warn_threshold` (default 5), a `+N gap` badge appears on the toggle and a banner inside the inspector body warns that the lists are a sample, not a complete enumeration. Read the queue counters above for totals when the gap is non-zero. Gap usually points to one of:

- A worker crashed mid-pickup and the `JobProcessing` listener didn't fire (TTL eventually cleans).
- Jobs are being pushed via raw `Queue::push()` outside Laravel's standard dispatch (no `JobQueued` event raised).
- The `pending.max_per_queue` cap kicked in on a high-volume queue (more jobs in the queue than the tracked sample).

To opt out (memory-bounded production), set `QUEUE_INSIGHTS_PENDING_ENABLED=false`. The listener writes become no-ops, the inspector toggle disappears, and existing keys age out via TTL.

## Batches

The dashboard renders a top-level **Batches** section above the Queues panel for jobs dispatched via `Bus::batch([...])->dispatch()`. Each row shows the batch name (or `Batch <short-id>` when unnamed), a progress bar driven by Laravel's authoritative `Bus::findBatch()` counts, and a counts triplet (`processed/total · failed · pending`). Cancelled batches show a red `cancelled` chip; finished + no-failures show a gray `finished` chip; jobs that fail when `allowFailures()` is off render `cancelled (first failure)` even before Laravel stamps `cancelled_at`.

Expanding a row reveals the per-uuid item list in enqueue order, with a status icon (✓ processed / ✗ failed / ⌛ pending) per item. Clicking a completed item opens the existing completed-job modal (by stream id); clicking a failed item opens the failed-job modal (by `failed_jobs.id`). The expand state is URL-shareable (`?batch=<batchId>`).

Every completed, failed, and pending row that belongs to a batch carries a small batch chip, clicking it opens the batch modal directly. The chip also renders inside the completed/failed/pending modal heroes, so an operator drilling into a single job can jump to its batch in one click. Inside an item modal that was opened from a batch, a `← Back to batch` button in the header returns you to the batch view without losing context (item modals stack visually on top of the batch modal).

The data is **event-captured into Redis** alongside Laravel's own `BatchRepository`. The `JobQueued` listener writes the following keys per batched job:

- `qi:batches:index` (sorted set), recent batchIds, ordered by first-seen unix timestamp. Used to enumerate batches without `SCAN`. Score-pruned on every enqueue (no whole-key TTL) so the head doesn't accumulate forever.
- `qi:batches:index:{connection}` (sorted set), per-connection roster, populated first-write-wins via Lua so a heterogeneous batch lands on exactly one connection. Same score-pruning as the aggregate index. Read by `/queue-insights/{connection}` scoped views.
- `qi:batch:{id}:connection` (string), single arbiter for first-write-wins. The atomic `SET … NX` on this key gates the per-connection ZADD inside `BatchClaimConnection.lua`. TTL is refreshed on every subsequent JobQueued for the same batch so the pointer doesn't age out under continued traffic.
- `qi:batch-uuid-conn:{uuid}` (string), uuid → connection side-key written for every batched job. Survives the JobProcessed/JobFailed pending-hash deletion so the heterogeneous-batch detail-view scope filter keeps working after members have run.
- `qi:batch:{id}:uuids` (list), RPUSH-ordered uuids in the batch. Bounded per batch by `batches.max_uuids_per_batch` (default 5000, best-effort under heavy concurrent dispatch).
- `qi:batch:uuid:{uuid}` (string), reverse lookup uuid → batchId, used to render the per-row chip on completed jobs.

`RecordJobProcessed` and `RecordJobFailed` add two more per-uuid index keys (`qi:uuid-completed:{uuid}` and `qi:uuid-failed:{uuid}`) so the per-item rollup can route clicks into the existing modal flows.

Bounded storage:

- ~50 bytes per uuid (`qi:batch:{id}:uuids` entry + `qi:batch:uuid:{uuid}` reverse pointer + index entry, amortised per batch).
- TTL on every per-batch key (`batches.ttl_seconds`, default 604800 = 7d). Self-pruning on the index via `ZREMRANGEBYSCORE` on each enqueue; per-batch keys age out via Redis EXPIRE.
- Authoritative counts (`pending_jobs`, `processed_jobs`, `failed_jobs`, `progress`, `finished_at`, `cancelled_at`) come from `Bus::findBatch()` on every render. The captured keys exist only to enumerate batches and resolve uuid → display row, NOT to count.

**Retry caveat.** `queue:retry` and `queue:retry-batch` use `Queue::pushRaw()`, which does NOT fire `JobQueued`, so a retried job won't refresh as a fresh pending entry in the per-item rollup. The retry will still flow through `JobProcessed` (which DOES fire), so a successful retry overwrites `qi:uuid-failed:{uuid}` with `qi:uuid-completed:{uuid}` and the row flips from ✗ to ✓ within one poll cycle.

To opt out, set `QUEUE_INSIGHTS_BATCHES_ENABLED=false`. The listener writes become no-ops, the Batches section disappears, and chips stop rendering on existing rows.

## Chained jobs

Jobs dispatched through `Bus::chain([...])->dispatch()` (or `$job->chain([...])`) carry the remaining chain inside the serialized command body. The dashboard renders that forward chain context in two places:

- **List rows**: completed and failed rows that have a follow-up job render a small `↳ NextJob (+N)` chip, where the leaf-class name shows the immediate next job and `+N` counts the further-down-chain jobs after it. Hover reveals the full FQCN and the total chained count.
- **Modal Chain section**: the completed and failed modals include a `Chain` block with the next job's FQCN, the `+N more chained` count, and the chain's queue/connection (when set on the job). The block is clickable: it swaps the modal into a "Chained jobs" detail view that lists every chained link in order with per-link connection/queue, and a `← Back` button (or `Esc`) returns to the job view. Drilling into a single chained job inside the **failed-job modal** also surfaces its constructor properties (extracted from the serialized payload, framework internals filtered out), same renderer used by the parent job's payload section. The completed-modal chain view stays metadata-only since the slim chain summary persisted on the stream entry doesn't retain user-bound data.

For **failed jobs** the source is `failed_jobs.payload.data.command` (Laravel always persists this column, so chain context renders regardless of the package's `capture.payloads` setting. For **completed jobs** the listener writes a JSON-encoded `chain` field (a list of `{class, connection, queue}` per chained link, typically ~80–300 bytes) onto each completed-stream entry at the time the job runs, also independent of `capture.payloads`. Per-link `connection`/`queue` overrides set on individual jobs are preserved) the displayed route reflects what Laravel will actually dispatch to. Encrypted jobs (`ShouldBeEncrypted`) carry an opaque base64 blob in `data.command`, so the chip and section are silently omitted for those rows. No error, just no signal.

**Backward chain visibility, `↰ From {parent}`.** As the parent enters processing, the package drops a short-lived **claim ticket** into Redis (per-shape FIFO list keyed by connection/queue/next-class/tail-fingerprint, default 60 s TTL). When the next link's `JobQueued` fires inside `CallQueuedHandler::call()`, the listener pops a ticket and stamps the parent's UUID onto the child's lineage hash. The completed-modal then renders `↰ From {uuid}` above the existing `↳ Next` row, and the failed-job markdown export gains a `**Parent:** \`{uuid}\` ({class})` line so AI-assisted triage can trace upstream of the failure point.

- **Disable** via `QUEUE_INSIGHTS_CHAIN_LINEAGE=false` (or `chain_lineage.enabled = false`). Both write and read sides short-circuit at the listener entry, zero Redis writes, zero overhead.
- **Encrypted parents (`ShouldBeEncrypted`) are silently skipped on both sides**: the serialized command body is opaque base64, so neither the parent's chain context nor the child's tail can be decoded. The child renders without a parent attribution; document this limitation if you mix encrypted chains with the dashboard.
- **Cross-worker collision tolerance.** Two parents with identical chain shape (same connection/queue/next-class/remaining-tail) running concurrently on different workers can attribute their children to each other in dispatch order rather than dispatch identity. Within a single worker chain dispatch is synchronous, so attribution is exact. Acceptable for an observability tool, see `internal/specs/backward-chain-lineage.md` §3 for the full collision model.
- **Class label is best-effort.** `qi:class:{uuid}` (TTL = `chain_lineage.lineage_ttl_seconds`, default 7 d) is the index that hydrates a parent UUID to a class name in the markdown export and modal. Past that horizon the UUID still renders, just without `(ClassName)`.
- **Click-through to the parent's modal is not in v1**: the lineage row is plain text plus a copy-to-clipboard button. Resolving a UUID to its target surface (completed stream id vs failed_jobs id) is a follow-up.

`queue:retry` re-runs a failed job through the normal worker path, so the eventual completed-stream entry of a retried chained job will still carry the correct `chain` field. The retry doesn't lose chain visibility. Backward lineage is keyed by uuid and survives the retry too: the existing `qi:lineage:{uuid}` is never overwritten with null.

## Job initiator

Where `↰ From {parent}` answers "which *job* ran before this one", the job initiator answers "which *request, command, or scheduled task* started the work", and, optionally, the exact line of code that dispatched it. Both surface as `Origin` and `Dispatched from` rows in the completed-, pending-, and failed-job modals, and in the failed-job markdown export.

- **Origin**: the coarse entry point: `http:{route}` for a job dispatched during a request, `artisan:{command}` inside a console command, `schedule:{task}` for one dispatched by a scheduled task. Origin rides Laravel's `Context`, so it's serialized into the job payload and **propagates into nested dispatches**, a job dispatched by another job inherits the root origin. Jobs dispatched outside any of those (tinker, a bare daemon) carry no origin.
- **Call site**: the exact `file:line` the `dispatch()` ran from, so two code paths that dispatch the same job class stay distinguishable. Opt-in: it costs one bounded `debug_backtrace()` per dispatch, so it's **off by default**.

```php
// config/queue-insights.php
'initiator' => [
    'enabled' => env('QUEUE_INSIGHTS_INITIATOR', true),
    'capture_origin' => true,
    'capture_call_site' => false,  // opt in for file:line precision
],
```

Origin capture is automatic (the package appends an HTTP middleware to the `web` / `api` groups and listens on `CommandStarting` plus the scheduler lifecycle. Coverage is best-effort: requests through custom route groups, and dispatches that run before the group middleware, carry no origin. Disable the whole feature with `QUEUE_INSIGHTS_INITIATOR=false`) listeners and the middleware become no-ops.
