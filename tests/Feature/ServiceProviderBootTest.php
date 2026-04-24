<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;

it('boots cleanly when the snapshot list is empty', function (): void {
    expect(app()->bound('config'))->toBeTrue()
        ->and(config('queue-insights.snapshots'))->toBeEmpty()
        ->and(config('queue-insights.key_prefix'))->toBe('qm:testing:');
});

it('fails at boot when snapshots contain a canonical-key collision', function (): void {
    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'foo/bar'],
        ['connection' => 'sqs', 'queue' => 'foo_bar'],
    ]);

    $provider = new QueueInsightsServiceProvider(app());

    expect(function () use ($provider): void {
        $provider->boot();
    })->toThrow(QueueInsightsConfigException::class, 'collision');
});

it('skips validation when the package is disabled', function (): void {
    config()->set('queue-insights.enabled', false);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'foo/bar'],
        ['connection' => 'sqs', 'queue' => 'foo_bar'],
    ]);

    $provider = new QueueInsightsServiceProvider(app());

    expect(function () use ($provider): void {
        $provider->boot();
    })->not->toThrow(Throwable::class);
});

it('registers the queue-insights config namespace', function (): void {
    expect(config('queue-insights'))->toBeArray()
        ->and(config('queue-insights.capture.payloads'))->toBe('off')
        ->and(config('queue-insights.retention.completed_stream_max'))->toBe(10000);
});
