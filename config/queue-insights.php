<?php

declare(strict_types=1);

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
        'payloads' => env('QUEUE_INSIGHTS_CAPTURE_PAYLOADS', 'off'),
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
         | Per-queue depth thresholds. When depth crosses the threshold a
         | QueueDepthExceeded event fires (subject to cooldown). Hook a listener
         | or Notification route on the host app side.
         |
         | ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000]
         */
        'thresholds' => [],
    ],

    'dashboard' => [
        'enabled' => true,
        'path' => 'queue-insights',
        'middleware' => ['web', 'auth', 'can:viewQueueInsights'],
    ],
];
