<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\ClassFilter;
use SanderMuller\QueueInsights\Prometheus\Collectors\DurationAggregateCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\JobsFailedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\JobsProcessedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotErrorsCollector;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_all');

    R::conn()->command('zadd', [KeyPrefix::make('classes:sqs'), 100, 'App\\Jobs\\Foo']);
});

it('JobsProcessedCollector emits one sample per (class, connection) from processed-total', function (): void {
    R::conn()->command('set', [KeyPrefix::make('processed-total:App\\Jobs\\Foo:sqs'), '42']);

    $samples = (new JobsProcessedCollector(new ClassFilter()))->collect()[0]->samples;

    expect($samples)->toHaveCount(1)
        ->and($samples[0]->name)
        ->toBe('queue_insights_jobs_processed_total')
        ->and($samples[0]->labels)
        ->toBe(['class' => 'App\\Jobs\\Foo', 'connection' => 'sqs'])
        ->and($samples[0]->value)
        ->toBe(42.0);
});

it('JobsFailedCollector reads failed-total per (class, connection)', function (): void {
    R::conn()->command('set', [KeyPrefix::make('failed-total:App\\Jobs\\Foo:sqs'), '7']);

    $samples = (new JobsFailedCollector(new ClassFilter()))->collect()[0]->samples;

    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)
        ->toBe(7.0);
});

it('Jobs collectors skip classes with no monotonic counter present', function (): void {
    // No `processed-total` key seeded for App\Jobs\Foo — collector must
    // not emit a phantom 0-sample.
    $samples = (new JobsProcessedCollector(new ClassFilter()))->collect()[0]->samples;

    expect($samples)
        ->toBeEmpty();
});

it('respects allow_list filter — non-listed class is skipped', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_list');
    config()->set('queue-insights.prometheus.class_filter.classes', ['App\\Jobs\\OtherClass']);

    R::conn()->command('set', [KeyPrefix::make('processed-total:App\\Jobs\\Foo:sqs'), '42']);

    $samples = (new JobsProcessedCollector(new ClassFilter()))->collect()[0]->samples;

    expect($samples)
        ->toBeEmpty();
});

it('DurationAggregateCollector emits 3 families (count_total, sum_seconds_total, max_seconds) from one HMGET', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('duration:App\\Jobs\\Foo:sqs'), 'count', '10']);
    R::conn()->command('hset', [KeyPrefix::make('duration:App\\Jobs\\Foo:sqs'), 'sum_ms', '12500']);
    R::conn()->command('hset', [KeyPrefix::make('duration:App\\Jobs\\Foo:sqs'), 'max_ms', '3200']);

    $families = (new DurationAggregateCollector(new ClassFilter()))->collect();

    expect($families)->toHaveCount(3)
        ->and($families[0]->name)
        ->toBe('queue_insights_job_duration_count_total')
        ->and($families[0]->type)
        ->toBe('counter')
        ->and($families[0]->samples[0]->value)
        ->toBe(10.0)
        ->and($families[1]->name)
        ->toBe('queue_insights_job_duration_sum_seconds_total')
        ->and($families[1]->samples[0]->value)
        ->toBe(12.5)
        ->and($families[2]->name)
        ->toBe('queue_insights_job_duration_max_seconds')
        ->and($families[2]->type)
        ->toBe('gauge')
        ->and($families[2]->samples[0]->value)
        ->toBe(3.2);
});

it('SnapshotErrorsCollector reads snapshot-errors-total per snapshot pair, missing key = 0', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'has-errors'],
        ['connection' => 'sqs', 'queue' => 'clean'],
    ]);

    R::conn()->command('set', [KeyPrefix::make('snapshot-errors-total:sqs:has-errors'), '5']);

    $samples = (new SnapshotErrorsCollector())->collect()[0]->samples;

    $byQueue = [];
    foreach ($samples as $s) {
        $byQueue[$s->labels['queue']] = $s->value;
    }

    expect($byQueue)->toBe(['has-errors' => 5.0, 'clean' => 0.0]);
});
