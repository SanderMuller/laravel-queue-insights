<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\ConfigValidator;

it('accepts a non-colliding snapshot list', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'default'],
            ['connection' => 'sqs', 'queue' => 'high'],
            ['connection' => 'redis', 'queue' => 'default'],
        ]);
    })->not->toThrow(Throwable::class);
});

it('does not collide when the same canonical key appears on different connections', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'database', 'queue' => 'default'],
        ]);
    })->not->toThrow(Throwable::class);
});

it('treats SQS URL and bare queue name as the same canonical entry', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'https://sqs.eu-west-1.amazonaws.com/123/my-q'],
            ['connection' => 'sqs', 'queue' => 'my-q'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'collision');
});

it('throws when two different inputs normalize to the same canonical key', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'foo/bar'],
            ['connection' => 'sqs', 'queue' => 'foo_bar'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'foo_bar');
});

it('rejects empty queue strings at boot', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => ''],
        ]);
    })->toThrow(QueueInsightsConfigException::class);
});

it('rejects malformed entries (missing keys)', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['queue' => 'default'],
        ]);
    })->toThrow(QueueInsightsConfigException::class)
        ->and(function (): void {
            ConfigValidator::validateSnapshots([
                ['connection' => 'sqs'],
            ]);
        })->toThrow(QueueInsightsConfigException::class);
});

it('rejects empty connection strings', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => '', 'queue' => 'default'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'empty connection');
});

it('accepts a well-formed pending block (or empty defaults)', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending([]);
        ConfigValidator::validatePending([
            'enabled' => true,
            'max_per_queue' => 5000,
            'ttl_seconds' => 3600,
            'gap_warn_threshold' => 10,
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects a non-boolean pending.enabled', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['enabled' => 'yes']);
    })->toThrow(QueueInsightsConfigException::class, 'pending.enabled must be a boolean');
});

it('rejects a non-int max_per_queue', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['max_per_queue' => '10000']);
    })->toThrow(QueueInsightsConfigException::class, 'pending.max_per_queue must be a positive integer');
});

it('rejects a zero max_per_queue', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['max_per_queue' => 0]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.max_per_queue must be a positive integer');
});

it('rejects a negative ttl_seconds', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['ttl_seconds' => -1]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.ttl_seconds must be a positive integer');
});

it('rejects a non-int gap_warn_threshold', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['gap_warn_threshold' => 1.5]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.gap_warn_threshold must be a positive integer');
});

it('accepts an empty alerts block', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([]))->not->toThrow(Throwable::class);
});

it('rejects a non-bool alerts.enabled', function (): void {
    expect(fn () => ConfigValidator::validateAlerts(['enabled' => 'yes']))
        ->toThrow(QueueInsightsConfigException::class, 'alerts.enabled must be a boolean');
});

it('rejects a negative alerts.cooldown_seconds', function (): void {
    expect(fn () => ConfigValidator::validateAlerts(['cooldown_seconds' => -1]))
        ->toThrow(QueueInsightsConfigException::class, 'cooldown_seconds');
});

it('rejects an invalid severity in a depth threshold', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'depth' => [
                'thresholds' => [
                    ['connection' => 'sqs', 'queue' => 'work', 'depth' => 100, 'severity' => 'urgent'],
                ],
            ],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'warning, critical');
});

it('rejects slack channel enabled without webhook_url', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'slack' => ['enabled' => true],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'webhook_url');
});

it('rejects mail channel enabled with empty to', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'mail' => ['enabled' => true, 'to' => []],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'mail.to');
});

it('rejects a non-int depth threshold value', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'thresholds' => [
            ['connection' => 'sqs', 'queue' => 'work', 'depth' => '1000'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'depth must be a non-negative integer');
});

it('logs a deprecation warning when legacy alerts.thresholds is set', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'legacy `alerts.thresholds`'));

    ConfigValidator::validateAlerts([
        'thresholds' => [
            ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000],
        ],
    ]);
});

it('rejects ratio outside [0, 1]', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'failure_rate' => ['ratio' => 1.5],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'ratio');
});
