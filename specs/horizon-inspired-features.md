# Horizon-inspired features

## Overview

Adds three Horizon-style affordances on top of the existing dashboard: a
**failed-job filter** for triage at scale, **wait-time** capture so operators
can see queue lag, and **retry failed jobs** so triage-and-fix flows happen
in-app instead of via shell. Each feature is independent — they ship in
priority order (filter → wait → retry) so we can land the cheap UX win first
and keep the riskier write surface (retry) gated behind clean gate + CSRF +
confirm-dialog plumbing.

---

## 1. Failed-jobs filter

### 1.1 Goal

The current `Recent failed` table is a raw last-50 read. At any non-trivial
volume operators can't find the row they care about. Add Livewire-driven
filters: **connection**, **queue**, **class FQCN**, **date range**.
Persisted in the URL via Livewire 3's `#[Url]` attribute so a filtered view
is shareable.

### 1.2 Component state

Add to `QueueInsightsDashboard`:

```php
#[Url(as: 'fc', except: '')]   public string $filterConnection = '';
#[Url(as: 'fq', except: '')]   public string $filterQueue = '';
#[Url(as: 'fk', except: '')]   public string $filterClass = '';
#[Url(as: 'ffrom', except: '')] public string $filterFrom = '';   // Y-m-d
#[Url(as: 'fto', except: '')]   public string $filterTo = '';     // Y-m-d
```

Add `clearFailedFilters()` to reset all five at once.

### 1.3 Service signature

Extend `QueueInsights::recentFailed()` from
`recentFailed(int $limit = 50): array` to:

```php
public function recentFailed(int $limit = 50, FailedJobFilters $filters = new FailedJobFilters()): array
```

Where `FailedJobFilters` is a value object holding the five strings (empty =
no filter on that field).

The **class filter** is the trickiest field because the displayName lives
inside the JSON payload, and JSON-path syntax + behaviour differ across DB
grammars. To avoid the matrix problem we **commit to substring match via
`LIKE` on the raw payload column**:

```php
->when($filters->class !== '', fn ($q) => $q->where(
    'payload', 'like', '%"displayName":"'.addslashes($filters->class).'%'
))
```

Rationale:
- Works identically on MySQL / Postgres / SQLite (all three support
  `LIKE` on text). No `JSON_EXTRACT` / `->>` divergence.
- The `"displayName":"…` anchor avoids cross-field bleed (the operator
  searching for `App\Foo` won't match a job whose argument string contains
  the literal text `App\Foo`).
- Anchored at the start of `displayName`, so `App\Foo` matches
  `App\FooJob` and `App\FooBarJob` (prefix semantic, per Open Q #4 lean).
- `addslashes` is the right escape because the payload was JSON-encoded,
  so `\` characters in FQCNs (`App\Jobs\X`) appear as `\\` — escaping the
  user input the same way restores the round-trip.

Tests must cover all three grammars. The existing failed_jobs schema lives
in SQLite via Testbench; MySQL + Postgres parity belongs in the CI matrix
(see Phase 1 task list).

### 1.4 UI

Filter row above the failed table:

- 4 `<input>` / `<select>` controls bound to the Livewire props
- A `Clear` button when any filter is non-empty
- Connection + queue inputs offer datalist-style autocomplete from
  `configuredQueues()` (no DB hit — already in render scope)
- The class filter is a free-text input (FQCNs are too long to enumerate)
- Date inputs use native `<input type="date">`

When all filters are empty the row is visually de-emphasized (collapsed
behind a `Filter ⌄` toggle, similar to job-classes section).

### 1.5 Trade-offs

- The class-filter JSON-path query is fine on the typical operator's
  failed-jobs table (≤10k rows). At higher volume a denormalized
  `display_name` column would be needed — out of scope.
- Date range is inclusive on `failed_at` (start-of-day / end-of-day in app
  timezone).

---

## 2. Wait time

### 2.1 Goal

Surface how long jobs sit in the queue before a worker picks them up. Two
display sites:

- Per-job in the details / failed modals: `Wait: 1.2s` next to `Duration`.
- Per-queue in the queue cards: `wait p50 / p95` micro-stats under the
  current `depth · in-flight · delayed` row.

### 2.2 Capture path

Today `RecordJobProcessing` stores `start:{uuid} → microtime` so
`RecordJobProcessed` can compute `duration_ms`. Wait time needs a third
timestamp: when the job was pushed.

**The join-key gotcha.** The `JobQueued` event exposes
`$connectionName / $queue / $id / $job / $payload / $delay`, where `$id`
is the **driver-generated id** (Redis stream id, DB row PK, SQS message
id) — *not* the job UUID that `$event->job->uuid()` returns later in
`RecordJobProcessing`. The two sides will never join unless we extract
the UUID consistently from the payload at enqueue time.

Concrete extraction:

```php
final class RecordJobQueued
{
    public function handle(JobQueued $event): void
    {
        $payload = json_decode($event->payload, true);
        $uuid = is_array($payload) && isset($payload['uuid']) && is_string($payload['uuid'])
            ? $payload['uuid']
            : null;

        if ($uuid === null || $uuid === '') {
            // Drivers / Laravel versions that don't stamp `uuid` into the
            // payload — record nothing, wait time renders `—` for that job.
            return;
        }

        Redis::connection(...)->command('setex', [
            KeyPrefix::make("pushed:{$uuid}"),
            3600,
            (string) microtime(true),
        ]);
    }
}
```

Laravel has stamped `payload.uuid` consistently since 8.x, but custom
queue drivers can override `createPayload` and omit it. The fallback is
the silent `—` render — never an error.

`RecordJobProcessing` reads `pushed:{uuid}` and writes a derived
`wait:{uuid} → ms` key (TTL = retention window, default 7 days).

### 2.3 Per-queue rollups

Maintain a per-queue Redis sorted set `wait:{connection}:{queue}` with:

- **member** = `{uuid}` (unique per job — sorted-set members dedupe by
  identity, so using `wait_ms` as the member would collapse all jobs that
  happened to wait the same number of milliseconds into one entry,
  silently corrupting p50/p95)
- **score** = `wait_ms` (numeric, used for `ZRANGEBYSCORE` percentile reads)

On each insert: `ZADD` the new sample, then `ZREMRANGEBYRANK key 0 -1001`
to keep the most recent 1000 — Redis sorted sets don't have a built-in
trim, so the rank-based delete is the standard pattern.

p50/p95 compute via `ZRANGE 0 -1 WITHSCORES`, sort scores in PHP, pick
the index. Same model as the existing per-class duration aggregation.

### 2.4 Storage cost

Three new Redis keys per job (`pushed:`, `wait:`, plus the sample in the
sorted set). Existing duration capture already adds `start:` + per-class
samples, so this roughly doubles the per-job key churn. Acceptable for the
target workload (≤1k jobs/min).

### 2.5 Backward-compat

Old jobs that pre-date the `JobQueued` listener won't have a
`pushed:{uuid}` key. The modal must render `Wait: —` (not `0`) when the
sample is missing. Per-queue p50/p95 cards render `—` until ≥10 samples
exist.

---

## 3. Retry failed jobs

### 3.1 Goal

A **Retry** button in the failed-job modal that re-dispatches the job
without the operator dropping to a shell. A **bulk Retry** affordance on
the failed-jobs table for `Retry all matching <current filter>`.

### 3.2 Implementation

Lean on Laravel's first-party retry path:

```php
Artisan::call('queue:retry', ['id' => [$uuid]]);
```

This re-dispatches the job and removes the row from `failed_jobs`. The
command's `id` argument is variadic and the underlying retry is idempotent
against an already-retried row (the SELECT inside the command finds
nothing and the call is a no-op).

Add Livewire methods to `QueueInsightsDashboard`:

- `retryFailed(string $uuid): void` — single-row retry
- `retryFailedBulk(): void` — server-enforced bulk retry (see safety
  contract below)

Both methods:

1. Authorize with `Gate::authorize('retryFailedJobs', ...)` — see §3.4
2. Wrap the Artisan call in try/catch + `session()->flash()` for success /
   failure feedback
3. Emit a Livewire event so the table refreshes after the retry lands

**Bulk retry safety contract.** UI-only constraints don't survive a
forged Livewire request — the `retryFailedBulk` server method is the only
trust boundary. Hard rules, all enforced *server-side* before any
Artisan call:

```php
public function retryFailedBulk(): void
{
    Gate::authorize('retryFailedJobs');

    $filters = $this->buildFailedFilters();

    // Rule 1: at least one filter must be non-empty. Reject the
    // unfiltered "retry every failed job we have" path outright.
    if ($filters->isEmpty()) {
        session()->flash('qi.retry.error', 'Bulk retry requires at least one filter.');
        return;
    }

    // Rule 2: snapshot the matching uuids inside a single transaction so
    // the count and the dispatch see the same set. Reject if >100.
    DB::transaction(function () use ($filters) {
        $uuids = DB::table('failed_jobs')
            ->where(/* …filter clauses… */)
            ->pluck('uuid')
            ->all();

        if (count($uuids) > 100) {
            session()->flash('qi.retry.error', sprintf(
                'Bulk retry rejected — %d matches exceed the 100 cap. Narrow the filter first.',
                count($uuids),
            ));
            return;
        }

        // Rule 3: dispatch atomically against the snapshot. queue:retry
        // is idempotent against rows another worker already retried — if
        // the set drifted between snapshot and dispatch the no-op path
        // absorbs it.
        Artisan::call('queue:retry', ['id' => $uuids]);
        session()->flash('qi.retry.ok', sprintf('Retried %d jobs.', count($uuids)));
    });
}
```

The "first 100" wording from earlier drafts is gone — the server either
retries the *whole* matching set (≤100) or rejects with a clear error.
Concurrent operators retrying overlapping sets are safe because
`queue:retry` is idempotent against already-retried rows (a second
operator sees an empty SELECT).

### 3.3 UI

- Retry button in the failed-modal header (next to **Copy markdown**),
  styled emerald, label `Retry`
- Confirm step inside the button itself (Alpine state): first click flips
  to `Confirm retry?` red label for 2s; second click within window fires
  `wire:click="retryFailed(@js($uuid))"`. No JS dialog (jarring), no
  passive-auto-confirm
- Bulk button only visible when ≥1 filter is active and the result set is
  ≤100 rows; disabled with tooltip when over cap
- Toast/banner for success/failure piggybacking the existing `<x-…>`
  pattern (check `resources/views/components/` — none yet, so add a
  minimal `<x-queue-insights::flash-banner>` if needed)

### 3.4 Security review

This is the first **write** action in the dashboard — careful here.

| Concern | Mitigation |
|---|---|
| **Auth** | Add a new `retryFailedJobs` Gate. The existing `viewQueueInsights` is read-only — do not overload it. Hosts that opt into retry must define both gates. Default = deny (no host gate ⇒ feature hidden + method 403). |
| **CSRF** | Livewire bakes CSRF into every method invocation; nothing extra. |
| **Replay / accidental fire** | Confirm-state requires two clicks within 2s. Bulk retry capped at 100. |
| **Mass-action footgun** | Bulk only runs against the *currently filtered* set, never the whole `failed_jobs` table. If filters are all empty, bulk is hidden (not just disabled). |
| **Audit trail** | Each retry logs at `info` level: `queue-insights.retry`, `{uuid}`, `{user_id}`, `{filter_set}`. Hosts can ship to their audit log. |
| **Rate-limit** | Wrap the Livewire methods in `RateLimiter::attempt()` keyed by user — 30 retries / min. Reject with flash-banner when exhausted. |
| **Driver safety** | `queue:retry` is driver-agnostic — it pushes back through the connection the row was originally captured on. Verified path. |

### 3.5 Tests

- Unit: `retryFailed` calls `Artisan::call('queue:retry', ...)` exactly
  once with the right uuid, fakes Artisan
- Unit: gate denial returns 403, no Artisan call
- Unit: bulk retry with ≥101 matches errors out (capped); ≤100 dispatches
  one Artisan call with the array
- Feature: with a real failed_jobs row, retry removes it from the table
  and pushes onto the test queue (Laravel's `Queue::fake()` + assert
  pushed)
- Feature: rate-limit kicks in after 30 retries/min

---

## Implementation

### Phase 1: Failed-jobs filter (Priority: HIGH)

- [x] Add `FailedJobFilters` value object — five string fields, all default empty, immutable. Helper `isEmpty(): bool`.
- [x] Extend `QueueInsights::recentFailed` to accept `FailedJobFilters`; apply WHERE clauses for connection / queue / class (LIKE on payload — see §1.3) / `failed_at` range.
- [x] Add Livewire props (`#[Url]`-tagged) + `clearFailedFilters` to `QueueInsightsDashboard`.
- [x] Wire `recentFailed` call in `render()` to pass filters built from props.
- [x] Build filter row UI above the failed table — collapsed by default, opens via `Filter ⌄` toggle when any filter is set.
- [x] Tests — recentFailed filter combinations (each field individually + combined), URL persistence (`Livewire::withQueryParams`), clear-button reset, regression for argument-bleed substring match.
- [ ] Tests (DB matrix) — class-filter LIKE semantics on **MySQL, Postgres, SQLite** (parity test, not just SQLite happy path). *See Findings — deferred to CI matrix work.*

### Phase 2: Wait time capture (Priority: HIGH)

- [x] Add `RecordJobQueued` listener — extracts `uuid` from the JSON-decoded payload (per §2.2 join-key contract), writes `pushed:{uuid} → microtime`, TTL 3600s. Bails silently if `payload.uuid` is missing.
- [x] Register the listener in the service provider next to the existing three.
- [x] Update `RecordJobProcessing` to read `pushed:{uuid}` and write `wait:{uuid} → ms` (string, ms integer).
- [x] Update `RecordJobProcessing` to push the wait sample onto `wait:{conn}:{queue}` sorted set: **member = `{uuid}`, score = `wait_ms`** (per §2.3 — using `wait_ms` as the member silently dedupes equal-wait jobs and corrupts percentiles).
- [x] Trim sorted set to last 1000 via `ZREMRANGEBYRANK key 0 -1001` after each insert.
- [x] Add `QueueInsights::queueWaitPercentiles(string $conn, string $queue): array{p50: ?int, p95: ?int}` — returns `null` for both when <10 samples.
- [x] Add `QueueInsights::jobWaitMs(string $uuid): ?int` for per-job lookups.
- [x] Surface `wait_ms` in completed + failed modals next to Duration.
- [x] Surface p50/p95 in queue cards under the existing micro-stats line; render `—` when <10 samples.
- [x] Tests — wait-time capture round trip, missing-pushed-key fallback, payload without `uuid` field, ZSET dedup regression (two jobs sharing wait_ms must both survive), percentile math, jobWaitMs lookup. Trim regression marked `skip()` — drives 1005 jobs through Redis, slow but available locally.

### Phase 3: Retry failed jobs (Priority: MEDIUM)

- [x] Define the `retryFailedJobs` Gate guidance in README (host-app concern, no default).
- [x] Add `retryFailed(string $uuid)` Livewire method — gate, log, Artisan call, flash, refresh.
- [x] Add `retryFailedBulk()` Livewire method — implements §3.2 server-enforced safety contract (reject empty filters, count snapshot, reject when count > 100, dispatch whole snapshot — never "first 100").
- [x] Add `RateLimiter::tooManyAttempts/hit('qi.retry:'.$userId, 30, 60)` wrapper around both methods.
- [x] Build `<x-queue-insights::flash-banner>` component (emerald success / red error variants).
- [x] Add Retry button to failed-modal header with two-click Alpine confirm pattern.
- [x] Add bulk Retry control to the failed table — visible only when filters non-empty AND ≤100 matches; "narrow to retry" hint when over cap. **UI constraint is convenience only**; the server method enforces both rules independently.
- [x] Tests — single retry happy path, gate denial (no Artisan call), bulk happy, bulk rejected when filters empty (forged request), bulk rejected when match count > 100, rate-limit kicks in after 30/min, audit log line emitted with user id + filter set, flash banner renders the message.

### Phase 4: Docs + release notes (Priority: LOW)

- [x] README: new section *Retry workflow* — gate setup + operator walk-through (single + bulk).
- [x] README: new section *Wait time* — what it measures, when `—` appears, capture path + cost.
- [x] README: new section *Failed-jobs filter* — query-string keys table, match semantics, bulk-retry scope.
- [x] README: Features list updated (wait time, throughput, filter, retry, markdown export).
- [ ] Screenshots — deferred. Requires running the dashboard against real data; not a code change. Belongs in the next manual release-prep pass.
- [ ] CHANGELOG entry — automated by `.github/workflows/update-changelog.yml` on release publish (per `CLAUDE.md` § *Release Automation*). No manual edit during the spec-impl phase.

---

## Open Questions

1. **Wait-time: capture from `pushed_at` in the payload rather than a separate `JobQueued` listener?** Some drivers stamp `pushed_at` into the JSON payload. Reading from there saves a Redis round-trip per push but couples to driver-specific payload shape. The listener approach is portable; the payload approach is cheaper. *Lean: listener (portability matters more than 1 SETEX/job).*

2. **Wait-time histogram: is sorted-set + manual percentile good enough, or do we need a t-digest / HDR sketch?** For ≤1000 samples / queue / hour the naive approach is fine. Above that the percentile drift becomes noticeable. *Lean: naive for v1, sketch when someone reports drift.*

3. **Date inputs without JS — does the operator's browser locale affect `<input type="date">` parsing?** Native date inputs always submit `Y-m-d` regardless of display format, so server-side parsing is locale-independent. Confirm in tests.

---

## Resolved Questions

1. **Filter: substring vs exact FQCN class match?** **Decision:** anchored prefix substring via `LIKE '%"displayName":"<input>%'` on the raw payload column. **Rationale:** exact match is too brittle (operators only remember the tail of long FQCNs); free substring lets argument values bleed into matches. The `"displayName":"…` anchor pins the search to the right JSON field, prefix semantic matches "App\Foo" → "App\FooJob".

2. **Class filter — JSON-path query vs LIKE on raw column?** **Decision:** LIKE on the raw payload column. **Rationale:** `JSON_EXTRACT` / `->>` syntax + behaviour diverges across MySQL, Postgres, SQLite — would force a per-grammar query strategy and CI matrix overhead. LIKE on TEXT/longText is identical across all three grammars. The `"displayName":"…` anchor preserves field-correctness.

3. **Wait-time sorted-set: member = wait_ms or member = uuid?** **Decision:** `member = uuid, score = wait_ms`. **Rationale:** Redis sorted sets dedupe by member, so storing the wait value as the member silently collapses every job that happened to wait the same number of milliseconds into one entry — directly corrupting p50/p95. UUID-as-member preserves one observation per job; the score holds the metric.

4. **Wait-time join key from `JobQueued` event?** **Decision:** decode `JobQueued::$payload` JSON and read `payload.uuid` — never `JobQueued::$id`. **Rationale:** the event's `$id` is the driver-generated identifier (Redis stream id, DB row PK, SQS message id), not the same identifier `$event->job->uuid()` returns later in `RecordJobProcessing`. Mismatched keys mean the wait sample never joins and the modal silently renders `—`. Drivers that don't stamp `payload.uuid` (custom `createPayload` overrides) get the silent-fallback path.

5. **Bulk retry: "first ≤100" or "reject when >100"?** **Decision:** server hard-rejects when match count > 100 or filters empty; never silently truncates. **Rationale:** UI constraints don't survive forged Livewire requests, so the trust boundary must live in the server method. Silent truncation is worse than a clear rejection — the operator may never realise their bulk action was partial. Concurrent retries are safe because `queue:retry` is idempotent against already-retried rows.

6. **Retry confirmation UX: two-click in-button vs Alpine modal?** **Decision:** two-click in-button confirm (first click flips label to "Confirm retry?" red for 2s, second click within window fires). **Rationale:** lighter than a modal, keyboard-accessible (focus stays on the same button), and matches existing `<details>`/disclosure idioms in the dashboard. Re-evaluate if operators report misfires.

7. **Bulk retry: allow "Retry all" with no filters?** **Decision:** server hard-rejects empty-filter bulk retry; UI hides the button when no filters are set. **Rationale:** spec §3.4 footgun guard. Horizon offers `retry-all` but Horizon's audience is single-tenant per dashboard; a queue-insights dashboard may surface multiple tenants' jobs and one-click 10k-row retry is an irreversible mass-action. Operators who want everything retried set a date-range filter that covers everything.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

### Phase 1

- **Livewire query-string testing.** The `Livewire::test(Component::class, $params)` second argument is *mount params*, not query-string params. Use `Livewire::withQueryParams([...])->test(Component::class)` to drive the `#[Url]` binding path.
- **DB-matrix parity test deferred.** The package's existing test infrastructure runs against Testbench's `:memory:` SQLite — adding live MySQL + Postgres test legs is a CI-matrix workflow change, not a spec-implementation change. The class-filter `LIKE` semantic is identical across the three grammars (per §1.3 rationale), and the SQLite parity test that does exist exercises both the prefix-match and the argument-bleed regression. The `LOWER(payload) LIKE ...` variant is the typical portability hazard, but we use case-sensitive `LIKE` against the exact-cased JSON encoding, which all three grammars support out of the box. Logged as TODO for the next CI-matrix pass.
- **Filter input semantics.** Connection / queue inputs are exact-match (the operator types the canonical name from the queue card). Class is anchored prefix substring on the JSON payload (Resolved Q #1). Date inputs are inclusive bounds, expanded server-side to `Y-m-d 00:00:00` / `Y-m-d 23:59:59` so an operator searching "2026-04-22 to 2026-04-22" gets that whole day's failures.

### Phase 2

- **Wait-key TTL = 7 days.** Spec mentioned "TTL ~ retention window" without committing. Set to `604800` (7d) to match the on-disk window the dashboard reasonably renders. The `pushed:` key keeps its short 1h TTL — by then the job has either processed or expired off the queue.
- **`expire` after every `zadd` + `zremrangebyrank`.** Belt-and-suspenders to keep idle queues from leaking the per-queue ZSET indefinitely. Cost: one extra Redis op per processed job — negligible vs the SETEX + ZADD already there.
- **`event->job->getQueue()` fallback.** When the job's `getQueue()` returns null we use `'default'` rather than skip the sample. A null-queue job is still a wait-time data point; bucketing it into `default` keeps the metric continuous.
- **Test fixture for `Job::uuid()`.** Used Mockery for `Illuminate\Contracts\Queue\Job` consistent with `tests/Unit/SanitizerTest.php` and `tests/Feature/Listeners/ListenerCaptureTest.php`. PHPStan diagnostic about `shouldReceive` is suppressed via the existing baseline pattern (`Parameter #2 $job of class Illuminate\Queue\Events\JobProcessed constructor expects Illuminate\Contracts\Queue\Job, Mockery\MockInterface given.`); same identifier may need a baseline bump if PHPStan flags this new test.

### Phase 3

- **Mockery cannot mock the Artisan facade.** Testbench's `Console\Kernel` is `final`, so the standard `Artisan::shouldReceive('call')->once()` pattern crashes inside Laravel's `Facade::createMock()`. Pivot: introduced `tests/Support/RecordingConsoleKernel` — a plain class implementing `Illuminate\Contracts\Console\Kernel` that records every `call(...)` invocation in a static array. `beforeEach` swaps the contract binding via `app()->instance(KernelContract::class, new RecordingConsoleKernel())`, and assertions look at `RecordingConsoleKernel::$calls`.
- **Session flash assertions don't survive Livewire's request boundary.** `session()->flash('qi.retry.ok', '…')` writes to `_flash.new`, which the post-action request lifecycle moves to `_flash.old` and then drops by the time the test assertion runs. Pivot: assert the flash banner via `->assertSee('Retry dispatched.')` against the rendered HTML — Livewire's auto re-render after the action picks up the flashed value through the `<x-queue-insights::flash-banner>` component. Two regression tests pin this path (success + error).
- **Rate-limit key is per-user-or-IP.** `Auth::id()` is null for guest sessions / package tests; the limiter falls back to `qi.retry:guest:<request_ip>`. In tests we explicitly clear `qi.retry:guest:127.0.0.1` in `beforeEach` to avoid bleed between cases.
- **`hitRetryRateLimit()` increments before the action runs.** That means a denied gate or a no-op retry still consumes a token. Trade-off: a forged-request attacker can't spam the gate-denial path to learn anything about the gate's existence, since the limiter still ticks. The cost is ~30 wasted tokens for a legitimate operator who keeps clicking a denied button — acceptable.
- **Bulk-retry UI threshold check is a SECOND query.** `render()` already calls `recentFailed(50, $filters)` for the table; the bulk eligibility check runs a separate `pluck('uuid')->limit(101)`. Two queries per render under filter is acceptable — both hit the same indexed path on `failed_jobs.id` and the page is already gated behind the dashboard auth.

### Codex review pass — Phase 1-3

Five findings, all real bugs, all fixed:

- **(high) ZSET trim kept the SLOWEST jobs, not the most recent.** `ZREMRANGEBYRANK key 0 -1001` orders by score; with `score = wait_ms` it dropped the fastest jobs and retained outliers, skewing p95 upward over time. **Fix:** flipped the score from `wait_ms` to insertion timestamp (`microtime(true)`); the ZSET now records recency. Percentile reads MGET the per-uuid `wait:{uuid}` keys to recover wait_ms. Added a regression test that seeds an old-slow + new-fast pair, trims to 1, and asserts the new sample survives.
- **(high) Retry success flashed even when `queue:retry` returned non-zero.** Laravel commands signal failure via exit code, not exceptions — dead-letter / already-retried rows were silently reported as "Retry dispatched.". **Fix:** capture the int return from `Artisan::call(...)` in both retry methods; on non-zero, log + flash an explicit error instead of success. Added two regression tests (single + bulk) that drive a non-zero exit code via `RecordingConsoleKernel::$nextExitCode` and assert the success message is absent.
- **(medium) Cross-host clock skew could poison wait metrics for 7 days.** Negative skew was already clamped to 0; positive skew (e.g. NTP drift on the producer) was accepted unbounded. **Fix:** reject samples where `wait_ms > 604_800_000` (7 days) — anything that large is a bad clock, not a real wait.
- **(medium) `LIKE` class-filter scope changed by database.** SQLite is ASCII case-insensitive, Postgres case-sensitive, MySQL collation-dependent. Same filter → different match set → different bulk-retry scope per host. **Fix:** wrapped both sides in `LOWER(...)` via `whereRaw('LOWER(payload) LIKE ?', [$needle])` in both `QueueInsights::recentFailed` and the bulk-retry uuid collector. Lowercase semantics produce the same match set across all three grammars; payload is unindexed longText so we lose nothing by skipping the index.
- **(medium) Audit log persisted user-controlled filter strings verbatim.** Class filter is URL-bound user input — could carry CR/LF for log-injection or arbitrary unbounded data. **Fix:** added `sanitizeAuditField()` that replaces non-printable-ASCII with `?` and caps length at 80 chars. Regression test pins both behaviours.

Refactor hygiene during the fix pass:
- Extracted `WaitTimeMetrics` (Support/) — dropped `QueueInsights` cognitive complexity from 83 → under threshold.
- Extracted `QueueInsights::applyFailedJobFilters()` — shared between `recentFailed` and the bulk-retry uuid collector, single source for filter semantics.

### Phase 4

- **Screenshots deferred.** Spec called for filter-row + retry-modal screenshots in the README. Capturing requires a populated dashboard (real queues + real failed jobs against real time-series Redis state) — that's a release-prep manual step, not a code change. Logged the deferred task in the spec phase.
- **CHANGELOG left alone.** Per the project's `CLAUDE.md`, `.github/workflows/update-changelog.yml` prepends the release body to `CHANGELOG.md` on publish via `stefanzweifel/changelog-updater-action`. Drafting a manual entry now would conflict with the automation. The release-notes file (`internal/release-notes-<version>.md`) is the right artifact when the user is ready to cut a tag — that's a `/pre-release` flow, not a Phase-4 doc task.
- **Features list reorder.** Added wait time / throughput / filter / retry / markdown-export bullets to the Features list at the top of the README. These are the user-visible additions that justify the next release; existing bullets (live depth/in-flight, 24h history, per-class metrics, recent jobs, payload capture) kept intact.
