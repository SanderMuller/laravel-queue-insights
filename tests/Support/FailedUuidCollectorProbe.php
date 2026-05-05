<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Support\FailedJobUuidCollector;

/**
 * Test-only proxy that calls into `@internal` `FailedJobUuidCollector`
 * from inside the package's root namespace so PHPStan accepts the call
 * from non-namespaced Pest test files.
 */
final class FailedUuidCollectorProbe
{
    /**
     * @return list<string>
     */
    public static function collect(FailedJobFilters $filters): array
    {
        return FailedJobUuidCollector::collect($filters);
    }
}
