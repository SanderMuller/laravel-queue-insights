<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Enums\CaptureMode;

return [
    'enabled' => env('QUEUE_INSIGHTS_ENABLED', true),

    /*
     | Redis connection NAME from config/database.php → redis.connections.
     | This is not a database number — the DB lives on the chosen connection's
     | `database` key.
     |
     | To use a dedicated DB: define a new connection in config/database.php
     | with the desired `database` number, then point QUEUE_INSIGHTS_REDIS at
     | that connection name. Keeps queue-insights keys isolated from Horizon /
     | sessions / cache / queue state on shared Redis instances.
     */
    'redis_connection' => env('QUEUE_INSIGHTS_REDIS', 'default'),

    'key_prefix' => env('QUEUE_INSIGHTS_KEY_PREFIX', 'qm:' . env('APP_ENV', 'production') . ':'),

    /*
     | Each entry: ['connection' => ..., 'queue' => ...]. Connection must exist in
     | config/queue.php. Driver is auto-detected via queue.connections.{name}.driver.
     */
    'snapshots' => array_values(array_filter([
        ['connection' => 'sqs', 'queue' => env('SQS_QUEUE')],
        ['connection' => 'sqs', 'queue' => env('SQS_HIGH_QUEUE')],
    ], fn (array $entry): bool => ! empty($entry['queue']))),

    'driver_overrides' => [],

    'capture' => [
        /*
         | Controls what the completed-jobs stream persists alongside metadata.
         |
         |   'off'      — no payload fields captured (default, safest).
         |   'metadata' — displayName / maxTries / timeout / backoff only; no
         |                user data, no serialized command body.
         |   'full'     — raw body after the bound PayloadSanitizer pass, then
         |                `redact_keys` regex pass + byte-cap.
         |
         | SECURITY: `full` stores serialized command bodies. Jobs may carry
         | PII or auth secrets that the default KeyRedactingSanitizer cannot
         | see inside `data.command`. Apps with sensitive jobs MUST bind a
         | custom PayloadSanitizer. See SECURITY.md.
         */
        'payloads' => env('QUEUE_INSIGHTS_CAPTURE_PAYLOADS', CaptureMode::Off->value),
        'redact_keys' => ['password', 'token', 'secret', 'api_?key', 'authorization'],
        'max_field_bytes' => 2048,
        'max_payload_bytes' => 16384,
    ],

    'retention' => [
        'history_hours' => 24,
        'processed_counters_days' => 7,
        'failed_counters_days' => 30,
        'completed_stream_max' => 10000,
        'per_class_stream_max' => 1000,
        'per_connection_stream_max' => 5000,

        /*
         | Per-class duration sample list cap. Drives slow_p95 detector
         | accuracy + p95 column on the Classes tab — 200 samples gives
         | reliable percentile reads while keeping Redis memory bounded
         | (each list holds at most this many int-as-string entries per
         | class, and the per-connection variant is capped to the same
         | value). Previously hardcoded at 500; reduce here to trim
         | per-class Redis memory by ~60 % without affecting p95 fidelity.
         */
        'duration_samples_cap' => 200,
    ],

    'schedule' => [
        'enabled' => true,
    ],

    /*
     | Job-class FQCNs whose failures are hidden from dashboards and skipped
     | by the failure_rate detector. Mirrors Horizon's horizon.silenced.
     |
     | Counter writes (qi:failed:{class}:{bucket}, qi:classes) are preserved
     | so silencing is reversible without losing history — silenced classes
     | drop off the Failed list, throughput failed-bucket sum, and outbound
     | alert notifications, but stay reachable via uuid-addressed lookups
     | (modal-by-uuid, batch-detail click-through). Empty list = feature off.
     |
     | Exact-match by FQCN. Closure / encrypted jobs surface as synthetic
     | labels (Closure@<hash>, Encrypted@<hash>) in counter-side reads — list
     | the synthetic label here to silence those surfaces; the failed-list
     | match is by displayName so closures may need both forms.
     */
    'silenced' => [
        // App\Jobs\IntermittentlyFailingJob::class,
    ],

    /*
     | Wildcard counterpart to `silenced`. Each entry is a `Str::is`-style
     | glob (e.g. `App\Jobs\Reports\*`) — silences every class whose FQCN
     | matches. Exact `silenced` entries are checked first; patterns are
     | the fallback path. Same surfaces as `silenced`: failed list, headline
     | failed counts, throughput failed bucket, failure_rate detector,
     | dispatcher guard, completed-row filter, silenced-tab roster, SQL
     | NOT LIKE exclusion. Empty list = patterns off.
     */
    'silenced_patterns' => [
        // 'App\\Jobs\\Reports\\*',
    ],

    'alerts' => [
        'enabled' => env('QUEUE_INSIGHTS_ALERTS_ENABLED', false),
        'cooldown_seconds' => 900,

        /*
         | DEPRECATED top-level threshold list. Kept for backwards compatibility
         | with hosts that published the package config before the alerts.rules
         | migration. When this list is non-empty it WINS over alerts.rules.depth.thresholds
         | and a deprecation warning is logged on boot. Move entries to
         | `alerts.rules.depth.thresholds` (which also supports per-entry severity).
         */
        'thresholds' => [],

        /*
         | Per-rule configuration. Each rule is opt-out via `enabled = false`.
         | See spec internal/specs/alerting.md §1 for detector semantics and
         | source keys.
         */
        'rules' => [
            'depth' => [
                'enabled' => true,
                /*
                 | ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000, 'severity' => AlertSeverity::Warning->value],
                 | ['connection' => 'sqs', 'queue' => 'work', 'depth' => 5000, 'severity' => AlertSeverity::Critical->value],
                 |
                 | When multiple entries match the same (connection, queue) the
                 | highest-severity matching threshold fires per tick.
                 */
                'thresholds' => [],
            ],

            'stalled' => [
                'enabled' => true,
                'idle_seconds' => 120,
                'min_depth' => 1,
                'severity' => AlertSeverity::Critical->value,
            ],

            'oldest_pending' => [
                'enabled' => true,
                'seconds' => 600,
                'severity' => AlertSeverity::Warning->value,
            ],

            'stuck_inflight' => [
                'enabled' => true,
                'seconds' => 300,
                'severity' => AlertSeverity::Warning->value,
            ],

            'failure_rate' => [
                'enabled' => true,
                'min_jobs' => 20,
                'ratio' => 0.10,
                'severity' => AlertSeverity::Warning->value,
            ],

            'slow_p95' => [
                'enabled' => false,
                // Per-class opt-in: ['App\Jobs\Foo' => 30000]
                'class_threshold_ms' => [],
                'severity' => AlertSeverity::Warning->value,
            ],

            'snapshot_errored' => [
                'enabled' => true,
                'severity' => AlertSeverity::Warning->value,
            ],

            'backlog_growing' => [
                'enabled' => false,
                // Depth-per-minute slope (least-squares regression over the
                // recent samples zset). 50/min ≈ "the queue is gaining one
                // job per second faster than the workers can drain". Tune
                // per workload.
                'min_slope_per_minute' => 50.0,
                // Don't fire until at least this many samples are in the
                // window (warm-up guard for fresh installs / cleared queues).
                'min_samples' => 5,
                'severity' => AlertSeverity::Warning->value,
            ],
        ],

        /*
         | Outbound notification channels. All opt-in. Cooldown gates these —
         | the dashboard always shows live state regardless.
         */
        'channels' => [
            'log' => [
                'enabled' => true,
                'level' => 'warning',
            ],
            'slack' => [
                'enabled' => false,
                'webhook_url' => env('QUEUE_INSIGHTS_SLACK_WEBHOOK'),
                // Optional display label for the destination channel
                // (e.g. "#queue-alerts"). Slack incoming-webhooks bind the
                // channel at creation time on Slack's side, so this value
                // is informational only — it does not override the
                // webhook's destination.
                'channel' => env('QUEUE_INSIGHTS_SLACK_CHANNEL'),
            ],
            'mail' => [
                'enabled' => false,
                'to' => [],
            ],
        ],
    ],

    'dashboard' => [
        'enabled' => true,
        'path' => 'queue-insights',
        'middleware' => ['web', 'auth', 'can:viewQueueInsights'],
        // Toggles `wire:poll.10s` on the dashboard root. Default-on for
        // production hosts (live snapshots refresh, alerts re-evaluate).
        // The workbench preview disables it because the seeded fixtures
        // are static — every poll would be wasted Redis traffic.
        'polling' => true,
        // Light/dark/system theme toggle. Master kill switch for the
        // dark-mode feature — gates the FOIT head script, the
        // `<meta name="color-scheme">` tag, and the toggle component
        // in the header. `dark:` Tailwind variants on individual
        // surfaces are emitted unconditionally; they're inert unless
        // a `.dark` class is on `<html>`, which only happens when
        // this flag is true. Default is on after the per-surface
        // audit (Phases 1-5 of the dark-mode spec); hosts can disable
        // via the env var if they need to revert. See
        // internal/specs/dashboard-dark-mode.md for rationale.
        'theme' => [
            'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
        ],
    ],

    /*
     | Pending & delayed-jobs tracking. When enabled, the JobQueued listener
     | stamps each queued job's metadata into Redis (hash + per-queue sorted
     | set) so the dashboard can show individual pending and delayed jobs
     | per queue — driver-agnostic, including SQS where queue-driver peeking
     | isn't possible.
     |
     | Storage cost is ~500 bytes per pending job, bounded by max_per_queue.
     | Set `enabled` to false on memory-bounded production to opt out.
     */
    'pending' => [
        'enabled' => env('QUEUE_INSIGHTS_PENDING_ENABLED', true),
        'max_per_queue' => 10000,
        'ttl_seconds' => 86400,
        // Tracked-vs-snapshot count drift threshold beyond which the
        // dashboard surfaces a "tracking gap" badge so operators know to
        // read the snapshot count, not the listed sample, as truth.
        'gap_warn_threshold' => 5,
    ],

    /*
     | Backward-chain lineage. When enabled, the parent job in a `Bus::chain`
     | drops a short-lived "claim ticket" into the cache as it enters
     | processing; the next link's `JobQueued` listener pops the ticket and
     | stamps `parent_uuid` onto the child's interim lineage record. The
     | dashboard surfaces it as a `↰ From {uuid}` row in the Chain section
     | and as a `Parent: {uuid}` line in the failed-job markdown export.
     |
     | Implementation depends on a Redis-backed cache store (LPUSH/RPOP on a
     | per-shape list bounds same-shape concurrent attribution to "FIFO order"
     | rather than "last writer wins"). When `chain_lineage.enabled = true`
     | and any monitored queue connection is non-sync, the boot-time validator
     | rejects the `array` cache driver.
     |
     | See spec internal/specs/backward-chain-lineage.md for the full design,
     | including the encrypted-payload limitation and cross-worker collision
     | tolerance.
     */
    'chain_lineage' => [
        'enabled' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE', true),
        // Redis connection name (from config/database.php → redis.connections)
        // for the claim list + interim lineage hash. null → reuses the package's
        // primary `redis_connection` above. Override only when you want lineage
        // tracking on a separate Redis instance from the rest of queue-insights
        // (operationally rare; the override exists for hosts that segregate
        // hot-path queue state from observability state).
        'redis_connection' => env('QUEUE_INSIGHTS_CHAIN_LINEAGE_REDIS'),
        // Claim-ticket TTL in seconds. Tickets that go unconsumed (parent
        // crashed before chain dispatch, child never queued, etc) age out at
        // this bound. 60s suits the common case of in-process chain dispatch
        // (child queues within milliseconds of parent JobProcessing); raise
        // for workloads where worker pickup latency commonly exceeds 60s.
        'claim_ttl_seconds' => 60,
        // Interim lineage-hash TTL in seconds. Holds `qi:lineage:{child-uuid}
        // = parent-uuid` until the child's listeners (Processing/Processed/
        // Failed) copy it into the durable record. Default 7d matches the
        // stream retention window so lookups stay valid for as long as the
        // child's row is queryable.
        'lineage_ttl_seconds' => 604800,
    ],

    /*
     | Batched-jobs tracking. When enabled, the JobQueued listener stamps
     | per-batch metadata (uuids list + reverse uuid→batchId lookup +
     | recent-batches index) into Redis so the dashboard can surface
     | per-batch progress and per-item rollups for `Bus::batch([...])`
     | dispatches.
     |
     | Storage cost is bounded per batch by `max_uuids_per_batch` and
     | per index entry by `ttl_seconds`. Set `enabled` to false to opt
     | out entirely — the section disappears and chips stop rendering.
     */
    'batches' => [
        'enabled' => env('QUEUE_INSIGHTS_BATCHES_ENABLED', true),
        'max_uuids_per_batch' => 5000,
        'max_per_query' => 100,
        'ttl_seconds' => 604800,
    ],

    /*
     | Laravel scheduler observability. When enabled, the package listens on
     | Illuminate\Console\Events\Scheduled* and records per-task definition
     | snapshots, per-run records (start/finish/exit/runtime/host/output),
     | per-task counters and rolling aggregates. Disabled by default —
     | existing queue-insights users opt in.
     |
     | See internal/specs/cron-monitoring/ for the data plane + dashboard
     | designs. Phase 1 (capture only) ships listeners + snapshot rebuild
     | + a `queue-insights:schedule:list` CLI command. Phases 2-4 add the
     | dashboard tab, missed/hung detection, and queue↔schedule correlation.
     */
    'scheduler' => [
        'enabled' => env('QUEUE_INSIGHTS_SCHEDULER_ENABLED', false),

        /*
         | Booted-time snapshot rebuild. The package re-reads `Schedule::events()`
         | on every `app->booted` and rewrites `qi:sched:tasks` + `qi:sched:tasks:order`.
         | Disable when the host pre-seeds the snapshot keys itself (custom import
         | script, workbench preview seeder) — otherwise that pre-seed gets
         | overwritten on the next request boot.
         */
        'snapshot_rebuild' => env('QUEUE_INSIGHTS_SCHEDULER_SNAPSHOT_REBUILD', true),

        /*
         | Per-run output capture. Same three-mode semantics as job payload
         | capture above.
         |   off      — no output captured. Exit code still recorded.
         |   metadata — exit code only.
         |   full     — exit code + stdout/stderr after PayloadSanitizer pass +
         |              byte-cap.
         */
        'capture' => [
            'output' => env('QUEUE_INSIGHTS_SCHEDULER_CAPTURE', 'metadata'),
            'max_output_bytes' => 8192,
        ],

        'retention' => [
            'run_ttl_seconds' => 604800,
            'runs_index_max' => 10000,
            'aggregate_ttl_hours' => 192,
            // Cap the per-run jobs zset (`qi:sched:run-jobs:{runId}`) so
            // a fan-out scheduled task that dispatches a very large
            // number of jobs can't grow the index unbounded. Oldest by
            // score evicted first.
            'run_jobs_max' => 5000,
        ],

        /*
         | Hung-task detection (Phase 3). A run is marked hung when no
         | Finished/Failed event arrives within `expected_runtime + grace`.
         | Expected runtime is the rolling p95 from aggregates; falls back
         | to grace_seconds alone for tasks with fewer than min_runs_for_p95.
         */
        'hung' => [
            'grace_seconds' => 300,
            'min_runs_for_p95' => 10,
        ],

        /*
         | Missed-run sweeper (Phase 3). A sweeper runs every sweep_seconds
         | and compares each task's expected fires-since-last-sweep (from
         | cron expression) to actual Starting events recorded.
         */
        'sweeper' => [
            'enabled' => true,
            'sweep_seconds' => 60,
            'drift_seconds' => 90,
        ],

        /*
         | External heartbeat URL the host hits from outside the app to
         | detect total scheduler outage (in-process can't detect itself
         | dead). Documented, not auto-configured.
         */
        'heartbeat' => [
            'enabled' => false,
            'url' => env('QUEUE_INSIGHTS_SCHEDULER_HEARTBEAT_URL'),
        ],

        'alerts' => [
            'enabled' => env('QUEUE_INSIGHTS_SCHEDULER_ALERTS_ENABLED', false),
            'cooldown_seconds' => 900,

            /*
             | Optional per-domain channel block. Mirror the queue-side
             | `alerts.channels` shape exactly. When this block is omitted
             | or every channel inside is disabled, the notification path
             | falls back to `alerts.channels` so single-list installs
             | route both queue and scheduler alerts through one config.
             |
             | Populate this block to route scheduler alerts to a different
             | Slack channel / mail recipient list / log channel. Example:
             |
             |     'channels' => [
             |         'slack' => ['enabled' => true, 'webhook_url' => env('QI_SCHED_SLACK')],
             |     ],
             */
            'channels' => [
                'log' => [
                    'enabled' => false,
                    'level' => 'warning',
                ],
                'slack' => [
                    'enabled' => false,
                    'webhook_url' => env('QUEUE_INSIGHTS_SCHEDULER_SLACK_WEBHOOK'),
                    'channel' => env('QUEUE_INSIGHTS_SCHEDULER_SLACK_CHANNEL'),
                ],
                'mail' => [
                    'enabled' => false,
                    'to' => [],
                ],
            ],
        ],

        'dashboard' => [
            'enabled' => true,
        ],
    ],

    /*
     | Settings for the `queue-insights:work` multi-connection supervisor.
     |
     | shutdown_grace_seconds bounds the window a child has to drain after
     | the parent forwards SIGTERM/SIGINT/SIGQUIT (or a non-zero sibling
     | exit triggers teardown). Survivors past the window get SIGKILL.
     |
     | Must be strictly greater than the largest child --timeout plus
     | driver-poll latency (SQS long-poll = 20s, redis BLPOP up to 5s).
     | Default 120 covers --timeout=60 + 20s long poll + headroom.
     */
    'work' => [
        'shutdown_grace_seconds' => 120,
    ],

    /*
     | Prometheus exposition. When enabled, the package mounts a /metrics
     | endpoint exposing queue-insights state in Prometheus 0.0.4 (or
     | OpenMetrics, via Accept negotiation) format. Default-off — adoption
     | is opt-in. See internal/specs/prometheus-export.md for the metric
     | catalogue and cardinality model.
     |
     | Auth: when both `token` and `allow_ips` are unset, the default
     | middleware fails closed with 403 — no silent open default. Set a
     | bearer token (preferred for shared infra) or an IP allow-list of
     | scrape sources. Hosts overriding `middleware` opt out of the
     | package default entirely.
     */
    'prometheus' => [
        'enabled' => env('QUEUE_INSIGHTS_PROMETHEUS_ENABLED', false),
        'path' => 'metrics',
        // null → use the package's `queue-insights.prometheus-auth` default.
        // An explicit array (even empty) overrides it.
        'middleware' => null,
        'token' => env('QUEUE_INSIGHTS_PROMETHEUS_TOKEN'),
        // CIDR strings — `Symfony\Component\HttpFoundation\IpUtils::checkIp`.
        'allow_ips' => [],

        /*
         | Per-class metric cardinality control. Per-queue metrics are
         | bounded by `snapshots` config; per-class metrics scale with
         | the class roster (potentially thousands).
         |
         |   - `allow_all`         emit a sample per class in
         |                         `classes:{connection}` zset.
         |   - `allow_list`        only emit samples for the FQCNs in
         |                         `classes`. Empty list → no per-class
         |                         metrics. THE DEFAULT.
         |   - `top_n_by_recency`  emit the top-N most-recently-seen
         |                         classes per connection (score is
         |                         last-seen unix ts, not throughput).
         */
        'class_filter' => [
            'mode' => 'allow_list',
            'classes' => [],
            'top_n' => 50,
        ],

        /*
         | Per-task metric cardinality control. Task rosters are small
         | (~100 typical), so default is `allow_all`. `allow_list` for
         | hosts who want to scrape only a subset; `top_n_by_recency`
         | is intentionally NOT supported (would require a new write
         | path — see internal/specs/cron-monitoring/07-platform-extensions.md §1.3).
         */
        'task_filter' => [
            'mode' => 'allow_all',
            'tasks' => [],
        ],

        /*
         | Per-metric toggles. Disable any family the host doesn't need
         | to keep the scrape body lean. The master gate is the
         | top-level `enabled` flag above.
         */
        'metrics' => [
            'queue_depth' => true,
            'inflight_jobs' => true,
            'pending_jobs' => true,
            'delayed_jobs' => true,
            'oldest_pending_age' => true,
            'oldest_inflight_age' => true,
            'jobs_processed_total' => true,
            'jobs_failed_total' => true,
            'job_duration' => true,
            'alert_active' => true,
            'snapshot_alive' => true,
            'snapshot_age' => true,
            'snapshot_errors_total' => true,
            // Scheduler families — opt-in. Master gate is also
            // `scheduler.enabled`; both must be true to emit samples.
            'scheduler_runs_total' => false,
            'scheduler_runtime_sum' => false,
            'scheduler_last_run_timestamp' => false,
            'scheduler_hung_total' => false,
            'scheduler_missed_total' => false,
            'scheduler_in_flight' => false,
            'scheduler_snapshot_age' => false,
            'scheduler_sweeper_age' => false,
        ],

        /*
         | Bounds thunder-herd when multiple Prometheus replicas scrape
         | concurrently. 0 disables both the per-request memoise and the
         | Redis cache (useful for debugging). Default 5s matches the
         | snapshot freshness floor.
         */
        'cache_ttl_seconds' => 5,

        /*
         | Push-gateway support (Phase 4). For short-lived processes
         | (CLI scripts, scheduled tasks) that exit before any scrape
         | can land. Long-running workers should be scraped, not pushed.
         */
        'pushgateway' => [
            'url' => env('QUEUE_INSIGHTS_PUSHGATEWAY_URL'),
            'job' => env('QUEUE_INSIGHTS_PUSHGATEWAY_JOB', 'laravel-queue-insights'),
            'instance' => env('QUEUE_INSIGHTS_PUSHGATEWAY_INSTANCE'),
        ],
    ],
];
