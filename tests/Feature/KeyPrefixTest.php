<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\KeyPrefix;

it('applies the configured key prefix', function (): void {
    config()->set('queue-insights.key_prefix', 'qm:testing:');

    expect(KeyPrefix::make('classes'))->toBe('qm:testing:classes')
        ->and(KeyPrefix::make('depth:sqs:default'))->toBe('qm:testing:depth:sqs:default');
});

it('relocates every key when the prefix changes', function (): void {
    config()->set('queue-insights.key_prefix', 'qm:production:');
    $before = KeyPrefix::make('depth:sqs:default');

    config()->set('queue-insights.key_prefix', 'qm:staging:');
    $after = KeyPrefix::make('depth:sqs:default');

    expect($before)->toBe('qm:production:depth:sqs:default')
        ->and($after)->toBe('qm:staging:depth:sqs:default')
        ->and($before)->not->toBe($after);
});

it('defaults to an env-scoped prefix in a fresh install', function (): void {
    // The package's published config defaults `key_prefix` to `qm:{APP_ENV}:`.
    $default = require __DIR__ . '/../../config/queue-insights.php';

    expect($default['key_prefix'])->toMatch('/^qm:[a-z0-9_-]+:$/i');
});
