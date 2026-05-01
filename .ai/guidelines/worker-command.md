# Worker command (`queue-insights:work`) — internals + edit points

AI-facing reference for `php artisan queue-insights:work`. End-user docs live in `README.md` (the `## Running workers` section); the design rationale lives in `internal/specs/queue-insights-work-command.md`. Read this **before** touching any of the files listed below.

## What it does

Reads `queue-insights.snapshots`, groups entries by connection, and spawns one `queue:work` subprocess per connection with a comma-joined `--queue=` priority list. The supervisor owns argv assembly + line-prefixed output + signal forwarding + grace + `SIGKILL` escalation + Bash 128+signum exit code propagation. Restart-on-crash and other liveness concerns belong to the host's process manager (systemd, supervisord, docker).

## Pipeline (do NOT reorder these phases)

```
handle()
  ├── pcntl gate          → refuse on POSIX hosts without pcntl
  ├── buildMap()          → snapshots → (connection => [queues])
  ├── resolveConnectionFilter() → --connection= array + CSV + dedup
  ├── collectForwardedFlags() → value + bool flags forwarded verbatim
  ├── buildProcesses()    → factory.make() per connection
  └── supervise()
        ├── startProcesses()        → Process::start($cb) per child
        ├── installSignalHandlers() → pcntl_async_signals + SIGTERM/INT/QUIT
        └── while (alive children) {
              reapExitedChildren()  → Process::wait + flush + record exit
              if first non-zero → terminateLiveChildren(SIGTERM)
              if grace expired  → escalateKill() (SIGKILL + stderr warning)
              Sleep::usleep(100_000)
            }
        → resolveExitCode(): firstFailure ?? 128+signum ?? SUCCESS
```

## Touchpoints — files that own this subsystem

| File | Role |
|---|---|
| `src/Console/QueueInsightsWorkCommand.php` | The supervisor command. `handle()` is the entry; `supervise()` is the wait loop, broken into `startProcesses` / `installSignalHandlers` / `reapExitedChildren` / `terminateLiveChildren` / `escalateKill` / `resolveExitCode` to stay under PHPStan's 20-cog ceiling. |
| `src/Console/WorkerProcessFactory.php` | Test seam interface. `make(connection, queues, forwardedFlags): Process`. |
| `src/Console/DefaultWorkerProcessFactory.php` | Production impl. Uses `PHP_BINARY` + `base_path('artisan')` + `Process(timeout: null)`. The `timeout: null` is load-bearing — Symfony Process defaults to 60s wall-clock and would kill daemon workers. |
| `src/Console/WorkerOutputStreams.php` + `DefaultWorkerOutputStreams.php` | Test seam for the `STDOUT` / `STDERR` stream resources. Tests rebind to `php://memory` for assertion. PHP's `STDOUT` constants are stream resources, not C `STDOUT_FILENO` ints. |
| `src/Console/WorkerOutputPrefixer.php` | Per-(connection, stream) carry buffer that prefixes complete lines with `[{connection}] ` and flushes the unterminated tail on child exit. The carry buffer is required because `Process::start($cb)` chunks may split a line mid-byte. |
| `src/Support/ConfigValidator.php::validateWork()` | Hard exception on non-positive int `shutdown_grace_seconds`. Wired into the provider's boot path. |
| `config/queue-insights.php` `work` block | `shutdown_grace_seconds = 120`. Strictly greater than max child `--timeout` + driver poll latency. |
| `tests/Fixtures/StubWorker.php` | Env-driven stub child for fan-out + signal tests. Standalone PHP script — no composer autoload, native `sleep()` not `Sleep::sleep()` (rector skip configured). |
| `tests/Fixtures/SupervisorLauncher.php` | Bootstraps a Testbench-backed Laravel app + binds a stub factory + runs the command. Used by the subprocess SIGTERM test. basePath points at `vendor/orchestra/testbench-core/laravel` because the package itself isn't an application. |

## Behavioural rules — DO NOT VIOLATE

1. **Always subprocess, never `Artisan::call('queue:work')` in-process.** `queue:work` installs process-global pcntl handlers, enables `pcntl_async_signals(true)` process-wide, never returns under normal operation, and can `exit()` the parent via `Worker::kill()` on memory exhaustion. In-process is unsafe for a supervisor parent. One process model: parent supervisor + N child subprocesses, always — even for single-connection installs.
2. **`pcntl_async_signals(true)` MUST run before `pcntl_signal()`.** Without async delivery, handlers only fire at `pcntl_signal_dispatch()` points and SIGTERM forwarding lags a poll tick. Both lines in `installSignalHandlers()` are required; one without the other is a correctness gap.
3. **Signal handler must be idempotent.** Repeat SIGTERM ticks during the grace window must not reset `$teardownStartedAt` — otherwise an operator who hits Ctrl-C twice resets the SIGKILL clock and never escalates. Guard on `$signalReceived !== null`.
4. **First non-zero child wins the parent exit code.** Subsequent non-zero exits during teardown are recorded in `$exits[]` and printed via `[%s] worker exited %d` but **do not** override `$firstFailure`. Single source of truth — the failure that triggered teardown.
5. **`shutdown_grace_seconds` > max child `--timeout` + driver poll latency.** SQS long-poll = 20s, redis BLPOP up to 5s. Default 120 covers `--timeout=60` + 20s + headroom. Lower values race child shutdown.
6. **Forward `--name=` verbatim.** Earlier drafts proposed rewriting `--name=foo` → `--name=foo-{connection}`; dropped. `queue:restart` reads a global cache key (`illuminate:queue:restart`) — it does not match on worker name. The rewrite buys nothing and breaks `Worker::popUsing($exactName, ...)` host integrations.
7. **`process->wait()` after `isRunning()` returns false.** Drains remaining pipe bytes through the streaming callback so the prefixer's carry buffer holds the true tail before flush. Without the explicit `wait()`, a fast child can exit with bytes still buffered.
8. **Refuse boot when pcntl is unavailable.** No silent orphan path. POSIX without pcntl + Windows both refuse — the orphan-children-on-parent-death failure mode is exactly what this command exists to prevent.
9. **No structured-log mode.** Forwarded streams are operator-facing. Tools needing structured ingestion listen on `JobProcessed` / `JobFailed` events the package already records.

## What NOT to do

- Don't add an `Artisan::call('queue:work')` fast path for single-connection installs. Resolved in spec §2.2 — see rule #1 above.
- Don't blanket-forward unknown `--*` flags to children. The forwarded set in `VALUE_FLAGS` + `BOOL_FLAGS` is **finite and explicit**. Adding a new flag is a one-line change in the const + one test row in the matrix; the upside is zero surprise behaviour when a future Laravel adds a flag we haven't audited.
- Don't add auto-restart on crash, worker pool sizing, dashboard worker-liveness panel, cross-connection priority, or per-queue flag overrides. All explicitly out of scope per spec §2.7. Operators who want N workers per connection run N units with `--connection=X`.
- Don't rewrite `--name=`. Rule #6 above.
- Don't use `pcntl_fork()` for tests. `proc_open` is the mandated pattern (spec §4.2) — PHPUnit's process model makes in-process pcntl handling unsafe. The supervisor-as-subprocess test in `tests/Feature/QueueInsightsWorkSignalTest.php` is the template.
- Don't use `Illuminate\Support\Sleep` in `tests/Fixtures/StubWorker.php`. That fixture runs as a standalone `php StubWorker.php` invocation without composer autoload. `rector.php` already skips `SleepFuncToSleepStaticCallRector` for that path.

