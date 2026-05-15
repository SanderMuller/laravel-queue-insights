<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\ConfiguredQueueList;
use SanderMuller\QueueInsights\Tests\Fixtures\FakeHorizonServiceProvider;

beforeEach(function (): void {
    config()->set('queue-insights.snapshots', []);
    config()->set('queue-insights.connection_aliases', []);
    config()->set('queue-insights.horizon.autodiscover', true);
    config()->set('queue-insights.horizon.environment');
    config()->set('horizon.defaults', []);
    // Test env resolves to 'testing' — this supervisor is what autodiscovery
    // would surface when the gate lets it through.
    config()->set('horizon.environments', [
        'testing' => [
            'supervisor-1' => ['connection' => 'redis-horizon', 'queue' => 'horizon-queue'],
        ],
    ]);
});

it('skips Horizon autodiscovery when autodiscover is false', function (): void {
    config()->set('queue-insights.horizon.autodiscover', false);
    // Provider IS loaded — false still wins.
    app()->register(FakeHorizonServiceProvider::class);

    expect(ConfiguredQueueList::build())->toBeEmpty();
});

it("under true, autodiscovers only when Horizon's provider is loaded", function (): void {
    // Provider not loaded → true gates the config-walk off.
    expect(ConfiguredQueueList::build())->toBeEmpty();

    // Provider (a HorizonServiceProvider subclass) loaded → true discovers.
    app()->register(FakeHorizonServiceProvider::class);

    expect(ConfiguredQueueList::build())->toBe([
        ['connection' => 'redis-horizon', 'queue' => 'horizon-queue'],
    ]);
});

it("under force, autodiscovers even when Horizon's provider is not loaded", function (): void {
    config()->set('queue-insights.horizon.autodiscover', 'force');
    // Provider deliberately NOT registered — 'force' ignores the runtime gate.

    expect(ConfiguredQueueList::build())->toBe([
        ['connection' => 'redis-horizon', 'queue' => 'horizon-queue'],
    ]);
});

it('keeps static snapshots[] entries regardless of the autodiscover value', function (string|bool $autodiscover): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'static-queue'],
    ]);
    config()->set('queue-insights.horizon.autodiscover', $autodiscover);

    // snapshots[] is never gated — the entry is present for false / true /
    // 'force' alike. The isolation guarantee: only the Horizon config-walk
    // is runtime-gated.
    expect(ConfiguredQueueList::build())->toContain(
        ['connection' => 'sqs', 'queue' => 'static-queue'],
    );
})->with([false, true, 'force']);

// Downstream-propagation note: every `configuredQueues()` consumer —
// `ConfiguredConnections::all()`, `QueueInsights::{allPendingJobs,
// allDelayedJobs,allInFlightJobs}`, nav, drift detector, Prometheus — is a
// pure derive function of `ConfiguredQueueList::build()`. The four `build()`
// tests above ARE the propagation guarantee: if `build()` is gated correctly,
// no downstream consumer can leak the gated connection. A runtime test
// calling those `@internal` derives would add no proof beyond the structural
// derivation (e.g. `array_column(build(), 'connection')` in
// `ConfiguredConnections::all()`).
