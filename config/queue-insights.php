<?php

declare(strict_types=1);

return [
    'enabled' => env('QUEUE_INSIGHTS_ENABLED', true),

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
