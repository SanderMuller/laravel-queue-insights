# Horizon supervisor auto-discovery

When `laravel/horizon` is installed, the dashboard Queues panel + pending/in-flight aggregation surface every Horizon supervisor's `{connection, queue}` from `horizon.environments` without hand-listing each one under `snapshots[]`. Static snapshot entries still win on collision (deduped on canonical `{connection, queue}`). Resolution mirrors Horizon's own `ProvisioningPlan` — `Str::is` glob match on env keys, recursive merge with `horizon.defaults` for supervisors that only override `processes`/`tries`/`balance`.

```php
// config/queue-insights.php — defaults (no operator action needed for most hosts):
'horizon' => [
    'autodiscover' => env('QUEUE_INSIGHTS_HORIZON_AUTODISCOVER', true),
    'environment' => env('QUEUE_INSIGHTS_HORIZON_ENV'), // null = app()->environment()
],
```

`autodiscover` is tri-state:

| Value            | Behaviour                                                                                                                             |
|------------------|---------------------------------------------------------------------------------------------------------------------------------------|
| `false`          | Never autodiscover — static `snapshots[]` only.                                                                                       |
| `true` (default) | Autodiscover **only when Horizon's service provider is loaded** in the running app — i.e. Horizon is actually the queue runtime here. |
| `'force'`        | Autodiscover from `config/horizon.php` regardless of whether the provider is loaded.                                                  |

The `true` gate matters on Vapor and similar setups: Horizon may be *configured* (`config/horizon.php` defines supervisors) while jobs actually run on SQS and Horizon's provider is excluded (`composer.json` `extra.laravel.dont-discover` + conditional registration). There, `true` correctly skips those supervisor queues — they'd never receive a snapshot. Set `QUEUE_INSIGHTS_HORIZON_AUTODISCOVER=force` if you genuinely want config-derived rows without the provider loaded. Set `QUEUE_INSIGHTS_HORIZON_ENV` when running multiple Horizon environments off the same Laravel `APP_ENV`.

When `autodiscover='force'` is set but the Horizon provider isn't loaded in the running app, the dashboard surfaces a top-level "Horizon not running" banner so operators don't read empty supervisor rows as a healthy state.
