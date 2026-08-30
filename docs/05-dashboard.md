# Dashboard

Mounts at `/queue-insights` when `dashboard.enabled=true` and `livewire/livewire` is installed. Define the `viewQueueInsights` Gate in your app:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('viewQueueInsights', fn ($user) => $user->isAdmin());
```

## Multi-connection scoping

When you monitor more than one queue connection (e.g. a multi-tenant app with one connection per tenant, or a mixed `sqs` + `redis` setup), the dashboard exposes connection as a **first-class navigation axis**, not a filter dropdown:

- `/queue-insights`: un-scoped, every monitored connection aggregated into one view.
- `/queue-insights/{connection}`: scoped to a single connection. Every panel narrows: queue rows, alerts strip, snapshot watchdog, pending/delayed/in-flight inspectors, batches, recent completed/failed lists, headline stats (jobs / min, throughput sparkline, p95 wait, max runtime), per-class metrics, and the alert-rules panel's depth thresholds.

A tab strip above the headline cards renders one tab per allowed connection plus an "All" tab. The strip auto-suppresses when only one connection is monitored.

The `{connection}` segment is constrained to the union of `snapshots.*.connection` and any Horizon-autodiscovered supervisor connections, typos 404 instead of mounting an empty dashboard. Pre-alias legacy URLs (`/queue-insights/redis` when `aliases.redis = redis-staging` is published) resolve to the canonical scope.

### Per-connection authorisation (optional)

Add the `viewQueueInsightsConnection` Gate to authorise per connection:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('viewQueueInsightsConnection', function ($user, string $connection): bool {
    return $user->canAccessTenant($connection);
});
```

When defined, the dashboard:

- 403s direct visits to `/queue-insights/{connection}` the user can't access.
- Hides denied connections from the tab strip.
- Renames the "All" tab to "All allowed" with a tooltip listing only the connections the user can already open (denied tenants are never named).

If the gate isn't defined, every monitored connection is reachable to anyone who passes `viewQueueInsights`, same behaviour as pre-spec versions.

### Audit log carries scope

Every retry log line (`queue-insights.retry`) includes `scope_connection` alongside the existing filter snapshot, so retries that span tenants are distinguishable from scoped retries.

### Upgrade note, per-connection class metrics need traffic to warm

Per-connection class counters (`processed:{class}:{connection}:{bucket}`, `failed:{class}:{connection}:{bucket}`, `duration:{class}:{connection}`, `last_run:{class}:{connection}`, `classes:{connection}` zset) are dual-written alongside the existing aggregate keys. Aggregate dashboards (`/queue-insights`) render correctly from second 0 after upgrade. Scoped views (`/queue-insights/{connection}`) for per-class p95 / throughput / 24h totals fill in as new events flow. The first hour after deploy will show `0` for class counts on a scoped view. Aggregate keys are unchanged so rolling back the package version is safe.

### Known limitations under scope

These v1 gaps surface only on the connection-scoped routes; the un-scoped dashboard is unaffected.

- **Heterogeneous batches are first-write-wins.** A `Bus::batch([...])` whose member jobs span multiple connections is indexed under the connection that dispatched the FIRST job. Other connections' scoped views won't see the batch. The detail/items view under scope reads `qi:batch-uuid-conn:{uuid}` (a dedicated uuid → connection side-key written when the job is queued, lifetime = `batches.ttl_seconds`) so cross-connection member uuids stay filtered even after the member has been processed/failed. Members past `batches.ttl_seconds` from queue time pass through. Operators relying on heterogeneous batches can fall back to the un-scoped view, which still shows every batch.
- **Recent completed list under a class drilldown post-filters by connection.** When the operator selects a class on a scoped view (`?class=App\\Foo` on `/queue-insights/redis`) the read routes to the per-class stream and post-filters rows by their `connection` field. The class stream caps at 1000 entries so the post-filter is cheap, but in extreme traffic skews a class drilldown may show fewer rows than the un-scoped class view. The plain scoped Recent completed list (no class drilldown) reads the dedicated `qi:completed:connection:{c}` stream and is unaffected.
- **Per-connection counter dual-write isn't atomic.** Aggregate and per-connection counters are written as separate Redis commands. A listener crash mid-write can leave the per-connection counter behind aggregate; later traffic re-fills it. Same best-effort guarantee the package's existing listeners offer; never produces phantom data.

## Retry permissions (write actions)

Retrying a failed job is a write action and needs its own Gate, separate from the read-only `viewQueueInsights`:

```php
Gate::define('retryFailedJobs', fn ($user) => $user->isAdmin());
```

Without that Gate, the Retry button stays hidden in the failed-job modal, the bulk Retry button stays hidden above the failed-jobs table, and direct calls to the underlying Livewire methods (`retryFailed`, `retryFailedBulk`) return 403.

The retry path uses Laravel's first-party `queue:retry` Artisan command, so it's idempotent against an already-retried row and works regardless of queue driver.

Guards on the retry path:

- 30 retries per minute, per user.
- The server rejects a bulk retry when the matching set is over 100 rows. The UI shows a "narrow to retry" hint instead of the action button.
- The server also rejects a bulk retry when no filter is set, so you can't accidentally one-click retry every failed job.
- Every retry writes an `info`-level log line with channel `queue-insights.retry`, including the user id, the active filter set, and `scope_connection` (the multi-connection scope, when set). Forward that to your audit log.

## Retry workflow

To triage a failed job:

1. Open the dashboard and find the row in the **Recent failed** list.
2. Optional: narrow with the inline filter toolbar above the list, connection, queue, class, or date range. The URL updates as you change a field, so the filtered view is shareable.
3. Click any row to open the failed-job modal. You'll see the exception, stack trace, payload, and metadata.
4. To retry one job, click *Retry* in the modal header. The button flips to a red "Confirm retry?" for two seconds; click again to fire. The modal closes and a green banner confirms dispatch. If `queue:retry` exits non-zero, you get a red banner instead of a misleading success.
5. To retry several at once, set at least one filter. A *Retry N jobs* button appears next to the section heading, with the same two-click confirm pattern. Anything matching more than 100 rows shows a *N matches · narrow to retry* hint instead of an action button.

A failed retry never leaves the dashboard in a half-broken state. The row is either re-dispatched (and removed from `failed_jobs`) or left alone.

## Filtering & scoping

There are two layers. **Global scope** (queue + class) is set by clicking a row in the queues tables (Overview section) or on the Classes section and applies to every list pane, Failed, Completed, Pending, Silenced. **Per-pane filters** narrow within a section on top of the active scope.

### Global scope

| Axis  | Set by                                                   | Cleared by                                     | Query-string key |
|-------|----------------------------------------------------------|------------------------------------------------|------------------|
| Class | clicking a class row on the **Classes** section                 | clicking the same row again, or the chip's `×` | `ck`             |
| Queue | clicking the connection/queue cell in the **Overview** section's queues tables | clicking the same row again, or the chip's `×` | `qk`             |

Active scope renders as an inline `Filtering by queue=… · class=…` strip above the section panes with a per-chip clear button. URL-shareable so a paste into chat preserves the operator's view.

When the active class scope IS a class in `queue-insights.silenced`, both Failed and Completed auto-reveal silenced rows so the lists don't read empty after the click. The "Show silenced" checkbox on each pane stays available for an explicit override.

### Per-pane filters

Both *Recent completed* and *Recent failed* have an always-visible filter toolbar above the list. Each field binds to a short query-string key, so a narrowed view is shareable and bookmarkable.

Connection, Queue, and Class are populated as `<select>` dropdowns from the configured queues (snapshots + Horizon autodiscovery) and the 24h class roster. No free-text typos. The Class dropdown on both panes binds to the global `?ck=` (same prop the Classes tab toggles), so picking a class on either pane scopes the other automatically.

### Recent failed filter

| Field      | Query-string key | Match semantics                                                      |
|------------|------------------|----------------------------------------------------------------------|
| Connection | `fc`             | Exact (`connection` column)                                          |
| Queue      | `fq`             | Exact (`queue` column)                                               |
| Class      | `ck`             | Anchored prefix substring on `payload.displayName`, case-insensitive |
| From       | `ffrom`          | `failed_at >= <Y-m-d> 00:00:00`                                      |
| To         | `fto`            | `failed_at <= <Y-m-d> 23:59:59`                                      |

The class filter avoids JSON-extract syntax, which diverges across MySQL, Postgres, and SQLite. Instead it runs `LOWER(payload) LIKE '%"displayname":"<input>%'`, which produces the same match set on all three. Picking `App\Jobs\SendEmail` matches that exact class, and the underlying `LIKE` semantics still anchor the prefix so e.g. selecting a parent namespace would match its descendants.

The filter row also drives the bulk-retry scope. The *Retry N jobs* button retries the same set the list is showing.

### Recent completed filter

Same five fields, separate state. Class is pre-filtered at the storage layer (per-class Redis stream key); the other four narrow the already-fetched 50-row default cap in PHP.

| Field      | Query-string key | Match semantics                                          |
|------------|------------------|----------------------------------------------------------|
| Connection | `cc`             | Case-insensitive substring                               |
| Queue      | `cqu`            | Case-insensitive substring                               |
| Class      | `ck`             | Exact FQCN, picks a single per-class stream             |
| From       | `cfrom`          | `processed_at >= <Y-m-d> 00:00:00`                       |
| To         | `cto`            | `processed_at <= <Y-m-d> 23:59:59`                       |
