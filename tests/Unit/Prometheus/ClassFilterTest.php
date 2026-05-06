<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\ClassFilter;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');

    R::conn()->command('zadd', [
        KeyPrefix::make('classes:sqs'),
        100, 'App\\Jobs\\Foo',
        200, 'App\\Jobs\\Bar',
        300, 'App\\Jobs\\Baz',
    ]);
});

it('allow_list returns only configured classes that exist on the connection', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_list');
    config()->set('queue-insights.prometheus.class_filter.classes', [
        'App\\Jobs\\Foo',
        'App\\Jobs\\Missing',
    ]);

    expect((new ClassFilter())->classesFor('sqs'))->toBe(['App\\Jobs\\Foo']);
});

it('allow_list with empty list returns nothing (per-class metrics off by default)', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_list');
    config()->set('queue-insights.prometheus.class_filter.classes', []);

    expect((new ClassFilter())->classesFor('sqs'))
        ->toBeEmpty();
});

it('allow_all returns every class in classes:{connection}', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_all');

    expect((new ClassFilter())->classesFor('sqs'))->toBe([
        'App\\Jobs\\Foo',
        'App\\Jobs\\Bar',
        'App\\Jobs\\Baz',
    ]);
});

it('allow_list dedupes when the same FQCN is listed twice (no duplicate Prometheus series — codex review)', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'allow_list');
    config()->set('queue-insights.prometheus.class_filter.classes', [
        'App\\Jobs\\Foo',
        'App\\Jobs\\Foo',
        'App\\Jobs\\Bar',
        'App\\Jobs\\Foo',
    ]);

    expect((new ClassFilter())->classesFor('sqs'))->toBe([
        'App\\Jobs\\Foo',
        'App\\Jobs\\Bar',
    ]);
});

it('top_n_by_recency picks the N most-recently-seen classes (highest score first)', function (): void {
    config()->set('queue-insights.prometheus.class_filter.mode', 'top_n_by_recency');
    config()->set('queue-insights.prometheus.class_filter.top_n', 2);

    expect((new ClassFilter())->classesFor('sqs'))->toBe([
        'App\\Jobs\\Baz',
        'App\\Jobs\\Bar',
    ]);
});
