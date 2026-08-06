<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Prometheus\Collectors\AlertActiveCollector;
use SanderMuller\QueueInsights\Prometheus\Registry;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

/**
 * Seeds the ActiveIssuesProvider Redis cache directly so the real
 * provider hydrates from cache instead of running the detector chain.
 * Sidesteps Mockery's `final` class restriction (BypassFinals is
 * scoped to QueueInsights.php only).
 *
 * @param  list<array{rule:string,severity:string,connection:string,queue:string,jobClass:?string,title:string,description:string,context:array<string,mixed>,detectedAt:int}>  $issues
 */
function seedActiveIssuesCache(array $issues): void
{
    R::conn()->command('setex', [
        KeyPrefix::make('alert:cache:active-issues'),
        300,
        (string) json_encode($issues),
    ]);
}

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
});

it('AlertActiveCollector emits one sample per active issue with diverging label sets', function (): void {
    seedActiveIssuesCache([
        [
            'rule' => 'depth',
            'severity' => 'critical',
            'connection' => 'sqs',
            'queue' => 'work',
            'jobClass' => null,
            'title' => 'Depth high',
            'description' => 'd',
            'context' => [],
            'detectedAt' => 1700000000,
        ],
        [
            'rule' => 'failure_rate',
            'severity' => 'warning',
            'connection' => '',
            'queue' => '',
            'jobClass' => 'App\\Jobs\\Foo',
            'title' => 'Failure rate',
            'description' => 'd',
            'context' => [],
            'detectedAt' => 1700000001,
        ],
    ]);

    $provider = $this->app->make(ActiveIssuesProvider::class);
    $samples = (new AlertActiveCollector($provider))->collect()[0]->samples;

    expect($samples)->toHaveCount(2);

    // Queue-scoped: no `class` label.
    expect($samples[0]->labels)->toBe([
        'rule' => 'depth',
        'connection' => 'sqs',
        'queue' => 'work',
        'severity' => 'critical',
    ]);
    expect($samples[0]->value)->toBe(1.0);

    // Class-scoped: includes `class` label, label set diverges by design.
    expect($samples[1]->labels)->toBe([
        'rule' => 'failure_rate',
        'connection' => '',
        'queue' => '',
        'severity' => 'warning',
        'class' => 'App\\Jobs\\Foo',
    ]);
});

it('AlertActiveCollector emits no samples when no issues are active', function (): void {
    seedActiveIssuesCache([]);

    $provider = $this->app->make(ActiveIssuesProvider::class);
    $samples = (new AlertActiveCollector($provider))->collect()[0]->samples;

    expect($samples)
        ->toBeEmpty();
});

it('ExporterSelfCollector populates the duration gauge after a Registry collect cycle', function (): void {
    config()->set('queue-insights.prometheus.cache_ttl_seconds', 0);

    $registry = $this->app->make(Registry::class);
    $body = $registry->render();

    expect($body)->toContain('# TYPE queue_insights_exporter_collect_duration_seconds gauge')
        ->toMatch('/queue_insights_exporter_collect_duration_seconds\s+[\d.]+/');
});
