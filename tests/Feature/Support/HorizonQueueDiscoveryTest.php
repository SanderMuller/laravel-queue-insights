<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\HorizonQueueDiscovery;
use SanderMuller\QueueInsights\Tests\Fixtures\FakeHorizonServiceProvider;

beforeEach(function (): void {
    // Each test isolates its Horizon config — reset.
    config()->set('queue-insights.horizon.autodiscover', true);
    config()->set('queue-insights.horizon.environment');
    config()->set('horizon.environments', []);
    config()->set('horizon.defaults', []);
});

it('isActive is false when no Horizon service provider is registered', function (): void {
    // The test harness registers only the package's own providers — Horizon's
    // provider is never loaded unless a test opts in.
    expect(HorizonQueueDiscovery::isActive())->toBeFalse();
});

it('isActive is true when a HorizonServiceProvider subclass is registered', function (): void {
    // Regression guard: Laravel keys loaded providers by exact class name, so
    // an exact-class `providerIsLoaded()` check would false-negative a
    // subclass. The `is_a(..., true)` scan must match it.
    app()->register(FakeHorizonServiceProvider::class);

    expect(HorizonQueueDiscovery::isActive())->toBeTrue();
});

it('returns empty when Horizon has no environments configured', function (): void {
    config()->set('horizon.environments', []);

    expect(HorizonQueueDiscovery::discover())
        ->toBeEmpty();
});

it('discovers single-string queue from a matched env supervisor', function (): void {
    config()->set('horizon.environments', [
        'testing' => [
            'supervisor-1' => ['connection' => 'redis-staging', 'queue' => 'default_staging'],
        ],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis-staging', 'queue' => 'default_staging'],
    ]);
});

it('splits a comma-joined queue string into separate pairs', function (): void {
    config()->set('horizon.environments', [
        'testing' => [
            'staging-premiums' => [
                'connection' => 'redis-staging',
                'queue' => 'premium-broadcast,premium-calculator',
            ],
        ],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis-staging', 'queue' => 'premium-broadcast'],
        ['connection' => 'redis-staging', 'queue' => 'premium-calculator'],
    ]);
});

it('accepts queue as a list of strings', function (): void {
    config()->set('horizon.environments', [
        'testing' => [
            'sup' => ['connection' => 'redis', 'queue' => ['high', 'low']],
        ],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'high'],
        ['connection' => 'redis', 'queue' => 'low'],
    ]);
});

it('matches wildcard env keys via Str::is and merges defaults recursively', function (): void {
    config()->set('horizon.defaults', [
        'supervisor-1' => ['connection' => 'redis', 'queue' => 'default', 'processes' => 1],
    ]);
    config()->set('horizon.environments', [
        // Env key glob: matches "testing" via Str::is.
        '*' => [
            // Overrides ONLY processes; connection + queue come from defaults.
            'supervisor-1' => ['processes' => 4],
            // Brand new supervisor not in defaults.
            'supervisor-2' => ['connection' => 'redis-staging', 'queue' => 'premium'],
        ],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis-staging', 'queue' => 'premium'],
    ]);
});

it('returns empty when no env key matches, even with defaults populated', function (): void {
    // Horizon would not deploy any supervisor here; we mirror that.
    config()->set('horizon.defaults', [
        'sup' => ['connection' => 'redis', 'queue' => 'default'],
    ]);
    config()->set('horizon.environments', [
        'production' => ['sup' => ['processes' => 2]],
    ]);
    app()->detectEnvironment(fn (): string => 'staging');

    expect(HorizonQueueDiscovery::discover())
        ->toBeEmpty();
});

it('honors queue-insights.horizon.environment override', function (): void {
    config()->set('queue-insights.horizon.environment', 'production');
    config()->set('horizon.environments', [
        'production' => ['sup' => ['connection' => 'redis', 'queue' => 'prod-queue']],
        'testing' => ['sup' => ['connection' => 'redis', 'queue' => 'test-queue']],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'prod-queue'],
    ]);
});

it('skips supervisors with missing or blank connection', function (): void {
    config()->set('horizon.environments', [
        'testing' => [
            'a' => ['connection' => '', 'queue' => 'foo'],
            'b' => ['queue' => 'foo'],
            'c' => ['connection' => 'redis', 'queue' => 'good'],
        ],
    ]);

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'good'],
    ]);
});

it('skips blank queue entries but keeps literal asterisk queue names', function (): void {
    config()->set('horizon.environments', [
        'testing' => [
            'sup' => ['connection' => 'redis', 'queue' => 'foo,, bar ,*'],
        ],
    ]);

    // Empty / whitespace-only entries dropped after trim; `*` is a legal
    // literal queue name and must NOT be skipped (Horizon's wildcard
    // applies to env keys, not queue names).
    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'foo'],
        ['connection' => 'redis', 'queue' => 'bar'],
        ['connection' => 'redis', 'queue' => '*'],
    ]);
});

it('first env-key glob match wins over later matches', function (): void {
    config()->set('horizon.environments', [
        'test*' => ['sup' => ['connection' => 'redis', 'queue' => 'first']],
        '*' => ['sup' => ['connection' => 'redis', 'queue' => 'fallback']],
    ]);
    app()->detectEnvironment(fn (): string => 'testing');

    expect(HorizonQueueDiscovery::discover())->toBe([
        ['connection' => 'redis', 'queue' => 'first'],
    ]);
});
