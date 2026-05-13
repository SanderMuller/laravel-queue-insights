<?php declare(strict_types=1);

use Illuminate\Contracts\Support\DeferrableProvider;
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
        ->and(config('queue-insights.retention.completed_stream_max'))->toBe(2000);
});

it('is not a deferred provider', function (): void {
    // Deferred providers only run register() + boot() when one of their
    // provides() services is resolved. This provider also loads routes,
    // registers Livewire components, subscribes queue listeners, and
    // schedules the snapshot command — all of which must fire on every
    // request. Hosts that auto-discover the package but never resolve a
    // provided service would otherwise see a silent no-op (404 on the
    // dashboard, listeners never wired). Locking the eager registration
    // here prevents a regression to DeferrableProvider.
    $provider = new QueueInsightsServiceProvider(app());

    expect($provider)->not->toBeInstanceOf(DeferrableProvider::class);
});
