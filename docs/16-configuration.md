# Configuration reference

Every key in `config/queue-insights.php`, with its default and what it changes. Publish the file first:

```bash
php artisan vendor:publish --tag=queue-insights-config
```

You don't have to publish it to use the package: the shipped defaults are merged, and most hosts only need the environment variables below. Publish when you need a key that has no env var (thresholds, alias maps, silenced classes, per-metric toggles).

Two rules that apply throughout:

- **Subsystems each carry their own `enabled` switch** (`dashboard.enabled`, `pending.enabled`, `alerts.enabled`, `prometheus.enabled`, `scheduler.enabled`, `batches.enabled`, `initiator.enabled`, `chain_lineage.enabled`, `failure_context.enabled`). Flip those individually rather than reaching for the top-level `enabled`.
- **Closures are not `config:cache`-safe.** `php artisan config:cache` fails on a closure in config. The one key where this bites is `failure_context.release_resolver` — see [Failure context capture](#failure-context-capture).

## Environment variables

Everything you can set without publishing the config file.

| Variable | Default | What it does |
|---|---|---|
| `QUEUE_INSIGHTS_ENABLED` | `true` | Master switch for the package. |
| `QUEUE_INSIGHTS_REDIS` | `default` | Redis **connection name** from `config/database.php` → `redis.connections`. Not a database number. |
| `QUEUE_INSIGHTS_KEY_PREFIX` | `qm:{APP_ENV}:` | Prefix for every Redis key the package writes. See [Key-prefix strategies](09-ops-runbook.md#key-prefix-strategies). |
| `QUEUE_INSIGHTS_REDIS_CLUSTER` | `false` | Wrap the prefix in a Redis hash tag so the keyspace pins to one slot. See [Redis Cluster](09-ops-runbook.md#redis-cluster). |
| `QUEUE_INSIGHTS_HORIZON_AUTODISCOVER` | `true` | Tri-state: `false`, `true`, or `force`. See [Horizon auto-discovery](11-horizon.md). |
| `QUEUE_INSIGHTS_HORIZON_ENV` | `null` | Which `horizon.environments.<env>` block to read. `null` uses `app()->environment()`. |
| `QUEUE_INSIGHTS_CAPTURE_PAYLOADS` | `off` | Completed-stream payload capture: `off`, `metadata`, or `full`. See [Payload capture](03-payload-capture.md). |
| `QUEUE_INSIGHTS_FAILURE_CONTEXT` | `true` | Capture `Context` and environment snapshots on failure. |
| `QUEUE_INSIGHTS_SENTRY_ORG` | `null` | Sentry org **slug**. Unset hides the "View in Sentry" button. See [Failure context](06-failure-context.md#sentry-deep-link). |
| `QUEUE_INSIGHTS_SENTRY_URL_TEMPLATE` | `https://{org}.sentry.io/issues/?query=trace:{trace}` | Deep-link template. `{org}` and `{trace}` are substituted. |
| `QUEUE_INSIGHTS_ALERTS_ENABLED` | `false` | Master switch for the nine queue detectors. See [Alerting](10-alerting.md). |
| `QUEUE_INSIGHTS_SLACK_WEBHOOK` | `null` | Incoming-webhook URL for the queue-side Slack channel. |
| `QUEUE_INSIGHTS_SLACK_CHANNEL` | `null` | Display label only — a webhook's destination is bound on Slack's side. |
| `QUEUE_INSIGHTS_DARK_MODE` | `true` | Master switch for the theme toggle. See [Dark mode](07-theming-and-embedding.md#dark-mode). |
| `QUEUE_INSIGHTS_CLOUD_THEME` | `true` | Offer the Cloud skin as a fourth toggle option. Requires the theme toggle. |
| `QUEUE_INSIGHTS_CLOCK_TOGGLE` | `true` | 12h / auto / 24h control in the header. |
| `QUEUE_INSIGHTS_REDIS_MEMORY_TILE` | `false` | Opt-in 7th headline tile summing `MEMORY USAGE` across the keyspace. |
| `QUEUE_INSIGHTS_PENDING_ENABLED` | `true` | Track pending and delayed jobs per queue. |
| `QUEUE_INSIGHTS_PENDING_CAPTURE_PAYLOADS` | `off` | Pending-row payload capture, on its own budget. |
| `QUEUE_INSIGHTS_PENDING_INCLUDE_COMMAND_BODY` | `false` | Persist `data.command` on pending rows under `full` mode. |
| `QUEUE_INSIGHTS_CHAIN_LINEAGE` | `true` | Backward `↰ From {parent}` lineage for chained jobs. |
| `QUEUE_INSIGHTS_CHAIN_LINEAGE_REDIS` | `null` | Separate Redis connection for lineage keys. `null` reuses the primary. |
| `QUEUE_INSIGHTS_INITIATOR` | `true` | Record where each job was dispatched from. |
| `QUEUE_INSIGHTS_BATCHES_ENABLED` | `true` | Track `Bus::batch()` progress and per-item rollups. |
| `QUEUE_INSIGHTS_SCHEDULER_ENABLED` | `false` | Scheduler observability. See [Scheduler observability](14-scheduler.md). |
| `QUEUE_INSIGHTS_SCHEDULER_SNAPSHOT_REBUILD` | `true` | Rebuild the task roster from `Schedule::events()` on scheduler-relevant console commands. |
| `QUEUE_INSIGHTS_SCHEDULER_CAPTURE` | `metadata` | Per-run output capture: `off`, `metadata`, or `full`. |
| `QUEUE_INSIGHTS_SCHEDULER_HEARTBEAT_URL` | `null` | External heartbeat URL. See [External heartbeat](14-scheduler.md#external-heartbeat). |
| `QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED` | `false` | Missed, hung, and failed scheduler alerts. |
| `QUEUE_INSIGHTS_SCHEDULER_SLACK_WEBHOOK` | `null` | Separate Slack webhook for scheduler-domain alerts. |
| `QUEUE_INSIGHTS_SCHEDULER_SLACK_CHANNEL` | `null` | Display label for the scheduler Slack destination. |
| `QUEUE_INSIGHTS_PROMETHEUS_ENABLED` | `false` | Mount the `/metrics` endpoint. See [Prometheus](13-prometheus.md). |
| `QUEUE_INSIGHTS_PROMETHEUS_TOKEN` | `null` | Bearer token for the scrape endpoint. Auth is fail-closed. |
| `QUEUE_INSIGHTS_PUSHGATEWAY_URL` | `null` | Pushgateway base URL for `queue-insights:prometheus-push`. |
| `QUEUE_INSIGHTS_PUSHGATEWAY_JOB` | `laravel-queue-insights` | `job` label on pushed metrics. |
| `QUEUE_INSIGHTS_PUSHGATEWAY_INSTANCE` | `null` | `instance` label on pushed metrics. |

## Connection and storage

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Master switch. |
| `redis_connection` | `default` | Connection name, not a database number — the DB lives on that connection's `database` key. To isolate the package's keys on shared Redis, define a new connection in `config/database.php` pointing at a dedicated DB and name it here. |
| `key_prefix` | `qm:{APP_ENV}:` | Prefix on every key the package writes. |
| `redis_cluster` | `false` | Wraps `key_prefix` in a `{…}` hash tag so the package's multi-key Lua scripts and pipelines stay CROSSSLOT-legal. An existing hash tag in your prefix is left alone, not double-wrapped. The matching connection in `config/database.php` must **also** be a cluster connection (a `clusters` block, or `options.cluster`) so the client follows `MOVED` redirects. |
| `snapshots` | two `sqs` entries, filtered on `SQS_QUEUE` and `SQS_HIGH_QUEUE` | The queues to capture. Each entry is `['connection' => …, 'queue' => …]`. The connection must exist in `config/queue.php`; the driver is auto-detected from `queue.connections.{name}.driver`. Recognised: `sqs`, `cloud`, `redis`, `database`, and `null` / `sync` (recorded with zero depth). Anything else logs a warning once per tick and snapshots nothing. |
| `driver_overrides` | `[]` | Force a driver for a connection whose real driver can't be auto-detected. Accepts a built-in name (`sqs`, `cloud`, `redis`, `database`, `null`), a `QueueSnapshotDriver` class-string, an instance, or a closure returning one. |
| `connection_aliases` | `[]` | Collapse several Laravel connection names onto one canonical key. Identity mappings (`A => A`) are allowed; transitive chains (`A => B`, `B => C`) and mutual cycles are rejected by the boot validator. See [Connection aliasing](12-connection-aliasing.md). |

### Managed platforms

Laravel Cloud's `cloud` connection and Vapor's SQS setup both work without a `driver_overrides` entry, and a connection carrying a queue-name suffix keys on its logical name throughout. Which queues to list, and what else is worth checking on those platforms, is on [Vapor and Laravel Cloud](15-vapor-and-cloud.md).

### `horizon`

| Key | Default | What it does |
|---|---|---|
| `horizon.autodiscover` | `true` | `false` never discovers. `true` discovers only when Horizon's service provider is loaded — so a Vapor or SQS host that configures Horizon without running it doesn't pick up supervisor queues that would never get a snapshot. `'force'` reads `config/horizon.php` regardless. |
| `horizon.environment` | `null` | Which `horizon.environments.<env>` block to read. `null` falls back to `app()->environment()`. |

## Payload capture

| Key | Default | What it does |
|---|---|---|
| `capture.payloads` | `off` | `off` persists nothing. `metadata` persists `displayName`, `maxTries`, `timeout`, `backoff`. `full` persists the raw body after the bound `PayloadSanitizer`, the `redact_keys` pass, and the byte cap. |
| `capture.redact_keys` | six patterns | Key-**name** regexes (anchored `^…$`, case-insensitive) applied to payload and failure-context fields. The `.*…*` wrapping catches the token anywhere in the key, so `access_token` and `db_password` match. Values are never scanned — a secret under an innocuous key still gets through. |
| `capture.max_field_bytes` | `2048` | Per-field cap. |
| `capture.max_payload_bytes` | `16384` | Whole-payload cap on the completed stream. |

::: warning
`full` stores serialized command bodies. The default `KeyRedactingSanitizer` walks JSON keys and cannot see inside `data.command`. Apps with sensitive jobs must bind a custom `PayloadSanitizer` — see [Payload capture](03-payload-capture.md) and [SECURITY.md](https://github.com/SanderMuller/laravel-queue-insights/blob/main/SECURITY.md).
:::

## Failure context capture

| Key | Default | What it does |
|---|---|---|
| `failure_context.enabled` | `true` | Capture context on job and scheduled-task failure. |
| `failure_context.capture_app_context` | `true` | Snapshot the visible Laravel `Context` facade. Hidden context is never captured — it holds package internals such as `qi_origin`. |
| `failure_context.context_keys` | `[]` | `[]` captures every visible key (still sanitized). A non-empty list restricts capture to exactly those keys. |
| `failure_context.capture_environment` | `true` | Record worker host, pid, app env, and release. |
| `failure_context.release_resolver` | `null` | Deploy identifier. `null` reads `env('APP_VERSION')`; a string is treated as a config key; a callable is invoked. **A closure here breaks `config:cache`** — for cached config use `null`, a config-key string, or set it from a provider with `config()->set(...)` at boot. |
| `failure_context.max_value_bytes` | `2048` | Per-value cap. |
| `failure_context.ttl_seconds` | `604800` (7 d) | How long a captured context stays readable. |

Context **values** run through the same `capture.redact_keys` pass as payloads, because the markdown export is pasted into issue trackers and AI tools.

## Sentry deep-link

| Key | Default | What it does |
|---|---|---|
| `sentry.organization` | `null` | Your Sentry org **slug** — the subdomain in `{slug}.sentry.io`, not the numeric id. `null` hides the button. |
| `sentry.issue_url_template` | `https://{org}.sentry.io/issues/?query=trace:{trace}` | The link target. Points at the issue stream, not the trace view, because the error event is captured under Sentry's error `sample_rate` and resolves even when the trace was sampled out. |

Unrelated to `alerts.channels.sentry` — that one delivers alerts, this one links the dashboard.

## Retention

| Key | Default | What it does |
|---|---|---|
| `retention.history_hours` | `24` | Throughput history window. |
| `retention.processed_counters_days` | `7` | Per-class processed counters. |
| `retention.failed_counters_days` | `30` | Per-class failed counters. |
| `retention.completed_stream_max` | `10000` | `MAXLEN ~` cap on the global completed stream. |
| `retention.per_class_stream_max` | `1000` | Cap per class. The dashboard's class-scoped reads bound their `LRANGE` here, so trimming it shrinks the scoped recent-completed window. |
| `retention.per_connection_stream_max` | `5000` | Cap per connection. |
| `retention.duration_samples_cap` | `500` | Per-class duration sample list. Feeds the `slow_p95` detector and the p95 column on the Classes tab. Lowering to 200 trims roughly 60 % of per-class Redis memory at some loss of percentile stability. |

Lower these to cut Redis memory at the cost of shallower drill-down history. They are explicit knobs, not defaults that will shift under you.

## Snapshot scheduling

| Key | Default | What it does |
|---|---|---|
| `schedule.enabled` | `true` | Auto-registers `queue-insights:snapshot` on Laravel's scheduler as `->everyMinute()->withoutOverlapping()`. Set `false` to wire it yourself with `Schedule::command('queue-insights:snapshot')`. |

## Silencing

| Key | Default | What it does |
|---|---|---|
| `silenced` | `[]` | Job-class FQCNs whose failures are hidden from the dashboard and skipped by the `failure_rate` detector. Exact match. Mirrors `horizon.silenced`. |
| `silenced_patterns` | `[]` | `Str::is` globs (`App\Jobs\Reports\*`). Exact entries are checked first; patterns are the fallback. |

Counter writes are preserved either way, so silencing is reversible without losing history. Silenced classes stay reachable by uuid — the failed-job modal and batch click-through still open them. Closure and encrypted jobs surface as `Closure@<hash>` and `Encrypted@<hash>`; the failed-list match is by display name, so a closure may need both forms listed. See [Silencing noisy jobs](10-alerting.md#silencing-noisy-jobs).

## Alerting

| Key | Default | What it does |
|---|---|---|
| `alerts.enabled` | `false` | Master switch. |
| `alerts.cooldown_seconds` | `900` | Per-rule, per-target cooldown on outbound notifications. The dashboard always shows live state regardless. |
| `alerts.thresholds` | `[]` | **Deprecated.** Pre-`alerts.rules` shape. When non-empty it wins over `alerts.rules.depth.thresholds` and logs a deprecation on boot. Move entries across — the new shape also supports per-entry severity. |

### `alerts.rules`

Every rule is opt-out via `enabled = false`.

| Rule | Default state | Keys |
|---|---|---|
| `depth` | on | `thresholds` — a list of `['connection', 'queue', 'depth', 'severity']` entries. When several match the same queue, the highest-severity match fires that tick. |
| `stalled` | on | `idle_seconds` (`120`), `min_depth` (`1`), `severity` (`critical`) |
| `oldest_pending` | on | `seconds` (`600`), `severity` (`warning`) |
| `stuck_inflight` | on | `seconds` (`300`), `severity` (`warning`) |
| `failure_rate` | on | `min_jobs` (`20`), `ratio` (`0.10`), `severity` (`warning`) |
| `job_failed` | **off** | `severity` (`warning`), `notify` (`true`). Event-driven, not a detector — dispatched from the `JobFailed` listener on a job's final failure, so it works on any driver with no snapshot. `notify => false` keeps the `JobFailedAlert` event firing while skipping this rule's synchronous channels, for apps that would rather dispatch their own async notification. |
| `slow_p95` | **off** | `class_threshold_ms` — per-class opt-in map, e.g. `['App\Jobs\Foo' => 30000]`. `severity` (`warning`) |
| `snapshot_errored` | on | `severity` (`warning`) |
| `backlog_growing` | **off** | `min_slope_per_minute` (`50.0`, least-squares regression over the recent-samples zset), `min_samples` (`5`, warm-up guard), `severity` (`warning`) |
| `connection_drift` | **off** | `severity` (`warning`). Heuristic: walks `config('queue.connections')` and `ZCARD`s the pending zset for every name that doesn't resolve to the configured canonical. Off by default so two-connection setups don't get surprise alerts. |

### `alerts.channels`

All opt-in, all gated by cooldown.

| Key | Default | What it does |
|---|---|---|
| `channels.log.enabled` | `true` | Write to the Laravel log. |
| `channels.log.level` | `warning` | Log level. |
| `channels.slack.enabled` | `false` | Post to an incoming webhook. |
| `channels.slack.webhook_url` | `null` | The webhook URL. |
| `channels.slack.channel` | `null` | Display label only. Slack binds a webhook's destination at creation time. |
| `channels.mail.enabled` | `false` | Send mail. |
| `channels.mail.to` | `[]` | Recipient list. |
| `channels.sentry.enabled` | `false` | Capture into whatever Sentry hub the host has initialised — no DSN here. Severity map is fixed (`critical` → error, `warning` → warning) and events fingerprint per rule and target, so Sentry groups them instead of opening an issue per tick. |

## Dashboard

| Key | Default | What it does |
|---|---|---|
| `dashboard.enabled` | `true` | Mount the dashboard route. |
| `dashboard.path` | `queue-insights` | Route path. |
| `dashboard.middleware` | `['web', 'auth', 'can:viewQueueInsights']` | Route middleware. The `can:` entry is what requires the Gate. |
| `dashboard.polling` | `true` | Toggles `wire:poll.10s` on the dashboard root. |
| `dashboard.theme.enabled` | `true` | Master switch for the light, dark, and system toggle. Gates the FOIT head script, the `color-scheme` meta tag, and the header control. `dark:` variants are emitted unconditionally but are inert without the `.dark` class this adds. |
| `dashboard.theme.cloud_enabled` | `true` | Adds Cloud as a fourth toggle option. Rides the same head script, so it requires `theme.enabled`. The default selection stays `system` — Cloud is offered, not forced. |
| `dashboard.clock.enabled` | `true` | Tri-state 12h / auto / 24h control. `auto` follows browser locale and the OS 24-hour preference; the choice persists in `localStorage['qi-clock']`. |
| `dashboard.redis_memory.enabled` | `false` | Opt-in 7th headline tile: sums `MEMORY USAGE` across every key under `key_prefix`. Off by default because the `SCAN` cost scales with keyspace size and `MEMORY USAGE` is O(N) of the sampled value. Cluster topologies iterate every master, multiplying the cost by node count. |
| `dashboard.redis_memory.cache_ttl` | `60` | Seconds to cache that total, so the cost is paid at most once a minute rather than on every 10-second poll. |

## Pending and delayed jobs

| Key | Default | What it does |
|---|---|---|
| `pending.enabled` | `true` | The `JobQueued` listener stamps each job's metadata into a hash plus a per-queue sorted set, which is what makes the pending view work on SQS where queue peeking isn't possible. Roughly 500 bytes per pending job. |
| `pending.max_per_queue` | `10000` | Rows tracked per queue. |
| `pending.ttl_seconds` | `86400` (24 h) | Row lifetime. |
| `pending.gap_warn_threshold` | `5` | Drift between the tracked count and the snapshot count beyond which the dashboard shows a "tracking gap" badge — the signal to read the snapshot count as truth, not the listed sample. |
| `pending.capture.payloads` | `off` | Separate budget from `capture.payloads`. Completed rows are `MAXLEN`-trimmed (`N × bytes`); pending hashes fan out as `max_per_queue × queues × TTL`, which is roughly 400 MB on a 10k-row × 10-queue host at 4 KB per row. |
| `pending.capture.max_payload_bytes` | `4096` | A quarter of the completed cap, so 10k pending rows land near 40 MB. |
| `pending.capture.include_command_body` | `false` | Persist `data.command` under `full` mode. Off by default: the `redact_keys` regex walks JSON keys and cannot reach properties inside a PHP-serialized blob, and a per-uuid hash with a 24-hour TTL is a wider confidentiality window than the bounded completed stream. Turn it on only after confirming your job classes carry no secrets as properties. |

## Chain lineage

| Key | Default | What it does |
|---|---|---|
| `chain_lineage.enabled` | `true` | The parent job in a `Bus::chain` drops a short-lived claim ticket as it enters processing; the next link's `JobQueued` listener pops it and stamps `parent_uuid` on the child. Surfaces as `↰ From {uuid}` in the Chain section and `Parent: {uuid}` in the markdown export. |
| `chain_lineage.redis_connection` | `null` | Separate Redis connection for the claim list and interim hash. `null` reuses the primary. Rare — for hosts that segregate hot-path queue state from observability state. |
| `chain_lineage.claim_ttl_seconds` | `60` | Unconsumed tickets age out here. 60 s suits in-process chain dispatch, where the child queues within milliseconds; raise it when worker pickup latency routinely exceeds that. |
| `chain_lineage.lineage_ttl_seconds` | `604800` (7 d) | Interim `qi:lineage:{child-uuid}` lifetime. Matches stream retention so lookups stay valid as long as the child's row is queryable. |

::: warning
This needs a Redis-backed cache store — `LPUSH`/`RPOP` on a per-shape list is what bounds concurrent attribution to FIFO order instead of last-writer-wins. With `chain_lineage.enabled = true` and any non-`sync` monitored connection, the boot validator rejects the `array` cache driver.
:::

## Job initiator

| Key | Default | What it does |
|---|---|---|
| `initiator.enabled` | `true` | Master switch. Off means no listener does initiator work and no keys are written. |
| `initiator.capture_origin` | `true` | The coarse origin — HTTP route, artisan command, or scheduled task. Rides Laravel `Context`, so it propagates into nested dispatches for free and needs no dedicated key. |
| `initiator.capture_call_site` | `false` | The exact `file:line` of the dispatch. Off by default: it costs one bounded `debug_backtrace()` per dispatch and writes a `qi:initiator:{uuid}` key. |
| `initiator.call_site_max_depth` | `30` | Frames walked looking for the first app frame. |
| `initiator.ttl_seconds` | `604800` (7 d) | Lifetime of the initiator key. Completed-job keys are shortened to a 60-second tail once copied. |
| `initiator.context_key` | `qi_origin` | Hidden `Context` key the entry-point hooks write and the listeners read. |

## Batches

| Key | Default | What it does |
|---|---|---|
| `batches.enabled` | `true` | Stamp per-batch metadata — uuid list, reverse uuid→batch lookup, recent-batches index — so the dashboard can show progress and per-item rollups. Off removes the section and stops the chips rendering. |
| `batches.max_uuids_per_batch` | `5000` | Cap on the tracked uuid list per batch. |
| `batches.max_per_query` | `100` | Cap on rows read per query. |
| `batches.ttl_seconds` | `604800` (7 d) | Index entry lifetime. |

## Scheduler

| Key | Default | What it does |
|---|---|---|
| `scheduler.enabled` | `false` | Listen on `Illuminate\Console\Events\Scheduled*` and record task snapshots, run records, counters, and rolling aggregates. |
| `scheduler.snapshot_rebuild` | `true` | Re-read `Schedule::events()` and rewrite the task roster when a scheduler-relevant console command starts. Console-only by design: `withSchedule()` and `routes/console.php` tasks don't exist in a web request, so a web-side rebuild would persist a partial roster. Disable when the host pre-seeds the roster itself — otherwise the pre-seed is overwritten. |
| `scheduler.snapshot_rebuild_commands` | `['schedule:*', 'queue-insights:*']` | Which commands trigger that rebuild. Exact names match literally; a trailing `*` matches by prefix. Keep it narrow so unrelated artisan commands pay no Redis round-trip, and add your own scheduler wrapper here if you run one instead of `schedule:run`. |
| `scheduler.capture.output` | `metadata` | `off` records the exit code only, `metadata` the same, `full` adds stdout and stderr after the `PayloadSanitizer` pass and byte cap. |
| `scheduler.capture.max_output_bytes` | `8192` | Output cap. |
| `scheduler.retention.run_ttl_seconds` | `604800` (7 d) | Per-run record lifetime. |
| `scheduler.retention.runs_index_max` | `10000` | Runs index cap. |
| `scheduler.retention.aggregate_ttl_hours` | `192` (8 d) | Rolling aggregate lifetime. |
| `scheduler.retention.run_jobs_max` | `5000` | Cap on the per-run correlated-jobs zset, so a fan-out task can't grow the index unbounded. Oldest by score is evicted first. |
| `scheduler.hung.grace_seconds` | `300` | A run is hung when no Finished or Failed event arrives within expected runtime plus this grace. |
| `scheduler.hung.min_runs_for_p95` | `10` | Below this many runs there is no rolling p95, so grace alone is used. |
| `scheduler.sweeper.enabled` | `true` | Compare each task's expected fires since the last sweep, derived from its cron expression, against the `Starting` events actually recorded. |
| `scheduler.sweeper.sweep_seconds` | `60` | Sweep interval. |
| `scheduler.sweeper.drift_seconds` | `90` | Tolerated drift before a fire counts as expected-but-unobserved. |
| `scheduler.sweeper.min_consecutive_misses` | `2` | Debounce. A single isolated miss is common infra noise on a per-minute scheduler — a late EventBridge tick, a transient Redis blip that drops the `Starting` write. The synthetic `missed` row is always recorded for the dashboard; `ScheduledTaskMissed` only dispatches once this many consecutive fires go unobserved. Set to `1` to alert on every isolated miss. |
| `scheduler.heartbeat.enabled` | `false` | External heartbeat, hit from outside the app — an in-process check cannot detect that the whole scheduler is dead. |
| `scheduler.heartbeat.url` | `null` | The URL to hit. |
| `scheduler.alerts.enabled` | `false` | Missed, hung, and failed scheduler alerts. |
| `scheduler.alerts.cooldown_seconds` | `900` | Per-`(taskKey, rule)` cooldown. |
| `scheduler.alerts.channels` | all disabled | Mirrors the queue-side `alerts.channels` shape exactly. When the block is omitted or every channel in it is disabled, delivery falls back to `alerts.channels` — populate it only to route scheduler alerts somewhere different. |
| `scheduler.dashboard.enabled` | `true` | Render the lazy-loaded Scheduled tasks panel. |

## Worker supervisor

| Key | Default | What it does |
|---|---|---|
| `work.shutdown_grace_seconds` | `120` | Window a child has to drain after the parent forwards `SIGTERM`, `SIGINT`, or `SIGQUIT`, or after a non-zero sibling exit triggers teardown. Survivors past the window get `SIGKILL`. Must be strictly greater than the largest child `--timeout` plus driver-poll latency — SQS long-poll is 20 s, Redis `BLPOP` up to 5 s, so 120 covers `--timeout=60` with headroom. See [`shutdown_grace_seconds` tuning](08-running-workers.md#shutdown-grace-seconds-tuning). |

## Prometheus

| Key | Default | What it does |
|---|---|---|
| `prometheus.enabled` | `false` | Mount the endpoint. |
| `prometheus.path` | `metrics` | Route path. |
| `prometheus.middleware` | `null` | `null` uses the package's `queue-insights.prometheus-auth` group. An explicit array — **including an empty one** — overrides it entirely and opts you out of the fail-closed default. |
| `prometheus.token` | `null` | Bearer token. Preferred on shared infra. |
| `prometheus.allow_ips` | `[]` | CIDR strings, matched with `IpUtils::checkIp`. |
| `prometheus.class_filter.mode` | `allow_list` | `allow_all` emits a sample per class in the roster. `allow_list` emits only the listed FQCNs — an empty list means no per-class metrics at all, which is the default. `top_n_by_recency` emits the N most recently seen classes per connection, scored by last-seen timestamp rather than throughput. |
| `prometheus.class_filter.classes` | `[]` | The allow-list. |
| `prometheus.class_filter.top_n` | `50` | N for `top_n_by_recency`. |
| `prometheus.task_filter.mode` | `allow_all` | Task rosters are small, so this defaults open. `allow_list` narrows it. `top_n_by_recency` is deliberately unsupported here. |
| `prometheus.task_filter.tasks` | `[]` | The allow-list. |
| `prometheus.metrics.*` | queue families on, scheduler families off | Per-family toggles. Disable any family you don't scrape to keep the body lean. The scheduler families additionally require `scheduler.enabled` — both must be true to emit samples. |
| `prometheus.cache_ttl_seconds` | `5` | Bounds thundering herd when several Prometheus replicas scrape at once. `0` disables both the per-request memoise and the Redis cache, which is useful when debugging. The default matches the snapshot freshness floor. |
| `prometheus.pushgateway.url` | `null` | Base URL for `queue-insights:prometheus-push`. For short-lived processes that exit before a scrape can land — long-running workers should be scraped, not pushed. |
| `prometheus.pushgateway.job` | `laravel-queue-insights` | `job` label. |
| `prometheus.pushgateway.instance` | `null` | `instance` label. |

Auth is fail-closed: with neither `token` nor `allow_ips` set, the default middleware answers `403`. There is no silent open default. See [Prometheus](13-prometheus.md).
