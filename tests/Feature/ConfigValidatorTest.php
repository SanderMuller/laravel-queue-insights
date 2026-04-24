<?php

declare(strict_types=1);

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
