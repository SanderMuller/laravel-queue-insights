<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use ReflectionMethod;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Enums\AlertSeverity;

/**
 * Test-only helpers for the silenced-jobs dispatcher belt-and-suspenders
 * suite. Lives in `SanderMuller\QueueInsights\Tests\Support` (inside the
 * package's root namespace) so PHPStan permits the `@internal` Issue +
 * IssueDispatcher access — the equivalent helpers in the Pest test file
 * are outside the root namespace and would trip `staticMethod.internalClass`.
 *
 * Not marked `@internal` itself: Pest closures live outside any namespace,
 * so consumers of this class would re-trip the same rule. Existing test-
 * support classes (`R`, `RedisAvailability`) follow the same pattern.
 */
final class SilencedAlertingHelpers
{
    public static function callDispatcherHandle(IssueDispatcher $dispatcher, Issue $issue): void
    {
        $method = new ReflectionMethod($dispatcher, 'handle');
        $method->invoke($dispatcher, $issue);
    }

    public static function fixtureClassScopedIssue(string $jobClass): Issue
    {
        return self::fixtureRuleScopedIssue('failure_rate', $jobClass);
    }

    /**
     * Build a class-scoped fixture Issue for an arbitrary rule. Used by
     * the dispatcher-guard tests to verify the silencing skip is rule-
     * scoped (failure_rate suppressed; slow_p95 unchanged per spec §1).
     */
    public static function fixtureRuleScopedIssue(string $rule, string $jobClass): Issue
    {
        return new Issue(
            rule: $rule,
            severity: AlertSeverity::Warning,
            connection: '',
            queue: '',
            jobClass: $jobClass,
            title: 'fixture',
            description: 'fixture',
            context: $rule === 'slow_p95'
                ? ['p95_ms' => 600, 'threshold_ms' => 500, 'sample_count' => 100]
                : ['failed' => 1, 'processed' => 1, 'total' => 2, 'ratio' => 0.5, 'ratio_threshold' => 0.1, 'min_jobs' => 1, 'bucket' => '2026050412'],
            detectedAt: 0,
        );
    }
}
