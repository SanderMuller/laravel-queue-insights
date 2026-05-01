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
    ],

    'schedule' => [
        'enabled' => true,
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
];
