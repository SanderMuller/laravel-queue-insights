# Connection aliasing

Use when one physical queue store is reached via multiple Laravel queue connection names, e.g. a `redis` connection for dispatchers + a `redis-staging` connection for Horizon workers, both pointing at the same Redis DB. Without aliasing, `JobQueued::$connectionName` (the dispatcher's name) and `JobProcessing::$connectionName` (the worker's name) diverge across every connection-keyed keyspace (pending/inflight zsets, per-class rosters + counters, Prometheus labels). Pending rows orphan; the dashboard panel scoped to the worker connection shows zero pending for a queue that's actively draining.

Publish the alias map to collapse both sides onto a canonical name:

```php
// config/queue-insights.php
'connection_aliases' => [
    'redis' => 'redis-staging',
    'redis-staging' => 'redis-staging',
],
```

Rules (enforced by `ConfigValidator::validateConnectionAliases`):

- identity mappings (`A => A`) allowed
- transitive chains (`A => B, B => C, B !== C`) rejected, flatten manually
- mutual cycles (`A => B, B => A`) rejected

Affects every connection-keyed Redis key and the `connection` label on every Prometheus metric. See [`UPGRADING.md`](https://github.com/SanderMuller/laravel-queue-insights/blob/main/UPGRADING.md) for the Prometheus relabel rule.
