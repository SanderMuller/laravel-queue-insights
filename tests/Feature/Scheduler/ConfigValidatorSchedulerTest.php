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
