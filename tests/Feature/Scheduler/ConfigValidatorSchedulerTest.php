<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\ConfigValidator;

it('accepts the default scheduler config block', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'enabled' => false,
            'capture' => ['output' => 'metadata', 'max_output_bytes' => 8192],
            'retention' => ['run_ttl_seconds' => 604800, 'runs_index_max' => 10000, 'aggregate_ttl_hours' => 192],
            'hung' => ['grace_seconds' => 300, 'min_runs_for_p95' => 10],
            'sweeper' => ['enabled' => true, 'sweep_seconds' => 60, 'drift_seconds' => 90],
            'heartbeat' => ['enabled' => false, 'url' => null],
            'alerts' => ['enabled' => false, 'cooldown_seconds' => 900],
            'dashboard' => ['enabled' => true],
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects a non-boolean enabled flag', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler(['enabled' => 'yes']);
    })->toThrow(QueueInsightsConfigException::class, 'scheduler.enabled');
});

it('rejects an invalid capture mode', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler(['capture' => ['output' => 'bogus']]);
    })->toThrow(QueueInsightsConfigException::class, 'capture.output');
});

it('rejects a non-positive retention integer', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler(['retention' => ['run_ttl_seconds' => 0]]);
    })->toThrow(QueueInsightsConfigException::class, 'run_ttl_seconds');
});

it('rejects an empty heartbeat url string', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler(['heartbeat' => ['url' => '']]);
    })->toThrow(QueueInsightsConfigException::class, 'heartbeat.url');
});

it('accepts a null heartbeat url', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler(['heartbeat' => ['enabled' => false, 'url' => null]]);
    })->not->toThrow(Throwable::class);
});

it('accepts the alerts.channels block matching the queue-side shape', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'alerts' => [
                'enabled' => true,
                'cooldown_seconds' => 900,
                'channels' => [
                    'log' => ['enabled' => true, 'level' => 'warning'],
                    'slack' => [
                        'enabled' => true,
                        'webhook_url' => 'https://hooks.example.com/SCHED',
                        'channel' => '#cron',
                    ],
                    'mail' => ['enabled' => true, 'to' => ['ops@example.com']],
                ],
            ],
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects scheduler.alerts.channels.slack.enabled without webhook_url', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'alerts' => [
                'channels' => [
                    'slack' => ['enabled' => true],
                ],
            ],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'scheduler.alerts.channels.slack.webhook_url');
});

it('rejects scheduler.alerts.channels.mail.enabled with empty to[]', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'alerts' => [
                'channels' => [
                    'mail' => ['enabled' => true, 'to' => []],
                ],
            ],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'scheduler.alerts.channels.mail.to');
});

it('rejects a non-bool scheduler.alerts.channels.sentry.enabled', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'alerts' => [
                'channels' => [
                    'sentry' => ['enabled' => 1],
                ],
            ],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'scheduler.alerts.channels.sentry.enabled');
});

it('rejects a non-string log level inside scheduler.alerts.channels.log', function (): void {
    expect(function (): void {
        ConfigValidator::validateScheduler([
            'alerts' => [
                'channels' => [
                    'log' => ['enabled' => true, 'level' => 5],
                ],
            ],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'scheduler.alerts.channels.log.level');
});
