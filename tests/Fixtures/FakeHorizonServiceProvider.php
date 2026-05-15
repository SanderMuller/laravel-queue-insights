<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Fixtures;

use Laravel\Horizon\HorizonServiceProvider;

/**
 * No-op subclass of Horizon's service provider. Registering this in a test
 * puts a `HorizonServiceProvider` subclass into `getLoadedProviders()` —
 * exactly what `HorizonQueueDiscovery::isActive()`'s subclass-aware
 * `is_a(..., true)` scan must match — without booting Horizon's real
 * services (queue connectors, Redis-backed repositories, routes).
 */
final class FakeHorizonServiceProvider extends HorizonServiceProvider
{
    public function register(): void
    {
        // Intentionally empty — the test only needs this class present in
        // the loaded-providers list.
    }

    public function boot(): void
    {
        // Intentionally empty.
    }
}
