# Running workers

`php artisan queue-insights:work` is a thin parent supervisor that reads `queue-insights.snapshots`, groups entries by connection, and spawns one `queue:work` subprocess per connection with `--queue=q1,q2,...` (Laravel's built-in priority list). For hosts running Laravel Horizon, use `horizon` instead, `queue-insights:work` is the alternative for projects without Horizon. (Horizon autodiscovery feeds the *dashboard*, but does not start workers from `horizon.environments` via this command.)

```bash
# Boot every monitored connection. One process per (connection, queue list).
php artisan queue-insights:work

# Restrict to one connection, e.g. when running per-connection systemd units.
# Both forms compose; they accept repeated flags AND comma-separated values.
php artisan queue-insights:work --connection=sqs
php artisan queue-insights:work --connection=sqs,redis
php artisan queue-insights:work --connection=sqs --connection=redis

# All `queue:work` flags forward verbatim to every child.
php artisan queue-insights:work --tries=5 --timeout=90 --memory=256 --max-jobs=1000
```

The supervisor owns argv assembly + signal forwarding + exit-code propagation. SIGTERM/SIGINT/SIGQUIT received by the parent are forwarded to every live child; after `queue-insights.work.shutdown_grace_seconds` (default 120) any survivors get SIGKILL with a stderr warning. Parent exit code is the **first** non-zero child's, or `128 + signum` for signal-initiated stops (Bash convention, lets systemd / supervisord distinguish operator-stop from supervisor-crash).

Output is line-prefixed with `[{connection}]` so `journalctl` / `docker logs` consumers can `grep` by connection without log shipping.

## Non-goals

This is **not** a Horizon replacement. The command is intentionally bounded to "one command, every monitored queue, one process group." Out of scope:

- **Auto-restart on crash**: host process manager owns liveness (systemd `Restart=on-failure`, supervisord, docker `restart: unless-stopped`).
- **Worker pool sizing / autoscaler**: one process per connection. Operators who want N workers per connection run N units with `--connection=X`.
- **Worker-liveness Redis keys + dashboard panel**: the existing `snapshot_command_dead` watchdog covers the snapshotter; no `qi:workers:*` heartbeat.
- **Cross-connection priority**: not possible while children are separate processes. Within-connection priority works (comma-list `--queue=q1,q2,q3`).
- **Per-queue flag overrides**: every child gets the same `--tries`, `--timeout`, etc. Per-queue sizing requires separate `--connection=X` units.

## Runtime requirements

- Requires the `pcntl` extension. POSIX hosts without it (and Windows generally) refuse to boot. The supervisor would otherwise orphan its children on shutdown.
- `queue:restart` works transparently, children share Laravel's global `illuminate:queue:restart` cache key reader.
- Pre-deploy ritual is unchanged: run `php artisan queue:restart` after a deploy, every child picks it up independently.

## `shutdown_grace_seconds` tuning

The default 120s covers `--timeout=60` + 20s SQS long-poll + headroom. The window must be **strictly greater than** the largest child `--timeout` plus driver poll latency (SQS long-poll = 20s, redis BLPOP up to 5s), otherwise SIGKILL races a still-draining job. Bump it if you raise `--timeout`.

```php
// config/queue-insights.php
'work' => [
    'shutdown_grace_seconds' => 120,
],
```
