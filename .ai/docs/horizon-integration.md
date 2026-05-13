# Horizon Integration

How the package reads Horizon's own config (`horizon.environments`,
`horizon.defaults`, `horizon.silenced`) so dashboards / detectors /
silencing stay aligned with what Horizon actually deploys.

## Touchpoints

- `src/Support/HorizonQueueDiscovery.php` — supervisor → `{connection, queue}` resolver.
- `src/Support/ConfiguredQueueList.php` — unions `snapshots[]` + Horizon discovery, canonicalises via `ConnectionAlias`.
- `src/Support/ConfiguredConnections.php` — derives the canonical connection roster from `ConfiguredQueueList`, so Horizon-only connections appear in the route + nav + per-connection gate sweep + Prometheus collectors (not just `snapshots[]`).
- `src/Support/SilencedJobs.php` — merges `config('horizon.silenced')` into the match set + display list + SQL exclusion.
- `src/QueueInsights::configuredQueues()` — single delegate to `ConfiguredQueueList::build`.
- `src/Support/ConfigValidator::validateHorizon()` — operator config sanity (`horizon.autodiscover` bool, `horizon.environment` non-empty string or null).
- `config/queue-insights.php` — `horizon.*` block.

## Horizon config shape (what we read)

- `horizon.environments` — map of env-key (Str::is glob) → supervisor-name → supervisor options. We pick the **first** env key that matches the current Laravel env via `Str::is`.
- `horizon.defaults` — supervisor template merged into the matched env via `array_replace_recursive($defaults, $matched)`. Per-supervisor merge — defaults supply missing `connection`/`queue` when the env block only overrides `processes`/`tries`/`balance`/etc.
- `horizon.silenced` — flat list of class FQCNs to silence.

This mirrors `Laravel\Horizon\ProvisioningPlan` exactly (see `vendor/laravel/horizon/src/ProvisioningPlan.php`).

## Behavioural rules

1. **Env match is glob-first-wins.** `'production-*'`, `'staging'`, `'*'` keys all match via `Str::is`. Order in `horizon.environments` matters.
2. **No env match → no discovery.** `horizon.defaults` is NOT a standalone fallback supervisor list — Horizon would not deploy anything if no env matched, so we mirror that.
3. **Wildcard queue names are NOT skipped.** Horizon's `*` is for env keys, not supervisor queue strings — `Worker::pop` uses `explode(',', $queue)` on literal names. A literal queue named `foo-*` is legal (rare but allowed).
4. **Producer + worker connection drift is solved by `connection_aliases`** — see `connection-aliases.md`. Autodiscovery surfaces the queue under the supervisor's `connection`; if the dispatcher uses a different Laravel connection name against the same physical store, the alias map collapses them.
5. **`horizon.silenced` merge is read-only.** We never write back to Horizon's config. Operator-edited `queue-insights.silenced` and `horizon.silenced` are merged at construction time of `SilencedJobs` (scoped binding — fresh per request).

## Operator config (in `config/queue-insights.php`)

```php
'horizon' => [
    'autodiscover' => env('QUEUE_INSIGHTS_HORIZON_AUTODISCOVER', true),
    'environment' => env('QUEUE_INSIGHTS_HORIZON_ENV'), // null = app()->environment()
],
```

## What NOT to do

- **Do not** use `Support\Config::array("horizon.*")` — it prepends `queue-insights.` to every key and would read the wrong namespace. Use the raw `config()` helper with a type guard (see `HorizonQueueDiscovery::readBlock`).
- **Do not** treat `horizon.defaults` as a per-env supervisor map. It is a per-supervisor template — `array_replace_recursive` is the merge primitive, not concatenation.
- **Do not** skip queues whose names contain `*` — Horizon does not glob-match supervisor queue names at runtime.
- **Do not** write to `horizon.silenced`. Operators edit `config/horizon.php` (or Spatie/Health writes there at boot); our side is strictly read-only.
- **Do not** add a `HorizonDetector` interface. The helper is a pure config reader — `class_exists(\Laravel\Horizon\Horizon::class)` is the right runtime gate. Tests autoload Horizon via `require-dev`.

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| Dashboard Queues panel missing Horizon supervisor queues | `queue-insights.horizon.autodiscover = false` OR Horizon not installed OR no env-key glob matches `APP_ENV` | Toggle the flag; install Horizon dev-dep; verify env keys |
| Operator's static `snapshots[]` entry not appearing | Dedup collision with a Horizon-derived entry on the same `{connection}|{canonical-queue}` | Snapshots win on insertion order; check `ConfiguredQueueList::build` log |
| Spatie/Health's `HealthQueueJob` not silenced in our dashboard | `silence_health_queue_job` flag in `config/health.php` is off, OR upstream package's service-provider boot order placed Horizon's silenced write after our `SilencedJobs` scoped construction | First check the flag; for the second, file an issue — `SilencedJobs` is `app()->scoped` so it constructs per-request, not at boot |

## Upstream refs

- [`Laravel\Horizon\ProvisioningPlan`](https://github.com/laravel/horizon/blob/5.x/src/ProvisioningPlan.php) — `Str::is` env-match + `array_replace_recursive` defaults merge.
- [`Laravel\Horizon\Horizon`](https://github.com/laravel/horizon/blob/5.x/src/Horizon.php) — the class-existence guard target.
- [Spatie Laravel-Health `silenceHealthQueueJob`](https://github.com/spatie/laravel-health/blob/main/src/HealthServiceProvider.php) — writes to `horizon.silenced` at boot.
