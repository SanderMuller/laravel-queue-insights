<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\KeyPrefix;

beforeEach(function (): void {
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.connection_aliases', []);
    config()->set('queue-insights.redis_cluster', false);
});

it('make prepends the configured prefix', function (): void {
    expect(KeyPrefix::make('foo'))->toBe('qmtest:foo');
});

it('make falls back to qm: when the prefix is empty', function (): void {
    config()->set('queue-insights.key_prefix', '');
    expect(KeyPrefix::make('foo'))->toBe('qm:foo');
});

it('classKey emits the aggregate variant when connection is null', function (): void {
    expect(KeyPrefix::classKey('processed-total', 'App\\Jobs\\Foo'))
        ->toBe('qmtest:processed-total:App\\Jobs\\Foo');
});

it('classKey appends the connection segment when provided', function (): void {
    expect(KeyPrefix::classKey('processed-total', 'App\\Jobs\\Foo', 'redis'))
        ->toBe('qmtest:processed-total:App\\Jobs\\Foo:redis');
});

it('classKey canonicalises the connection segment under aliases', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(KeyPrefix::classKey('processed-total', 'App\\Jobs\\Foo', 'redis'))
        ->toBe('qmtest:processed-total:App\\Jobs\\Foo:redis-staging');
});

it('queueKey canonicalises both connection and queue segments', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(KeyPrefix::queueKey('pending-zset', 'redis', 'premium-calculator'))
        ->toBe('qmtest:pending-zset:redis-staging:premium-calculator');
});

it('queueKey falls back to the connection-default queue when input queue is blank', function (): void {
    config()->set('queue.connections.redis-staging.queue', 'default_staging');
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(KeyPrefix::queueKey('pending-zset', 'redis', ''))
        ->toBe('qmtest:pending-zset:redis-staging:default_staging');
});

it('queueKey normalises an SQS-URL queue input', function (): void {
    expect(KeyPrefix::queueKey('depth', 'sqs', 'https://sqs.eu-west-1.amazonaws.com/123/work-queue'))
        ->toBe('qmtest:depth:sqs:work-queue');
});

it('wraps the prefix in a Redis hash tag when redis_cluster is on', function (): void {
    config()->set('queue-insights.redis_cluster', true);

    // The whole prefix becomes the hash tag — Redis hashes only `qmtest:`,
    // so every package key lands on one slot regardless of its suffix.
    expect(KeyPrefix::make('pending-zset:sqs:default'))
        ->toBe('{qmtest:}pending-zset:sqs:default');
});

it('co-locates classKey and queueKey under the cluster hash tag', function (): void {
    config()->set('queue-insights.redis_cluster', true);

    expect(KeyPrefix::classKey('duration', 'App\\Jobs\\Foo'))
        ->toBe('{qmtest:}duration:App\\Jobs\\Foo')
        ->and(KeyPrefix::queueKey('pending-zset', 'redis', 'q'))
        ->toBe('{qmtest:}pending-zset:redis:q');
});

it('does not double-wrap a prefix that already carries a hash tag', function (): void {
    config()->set('queue-insights.redis_cluster', true);
    config()->set('queue-insights.key_prefix', 'qm:{staging}:');

    // Operator placed their own tag — left exactly as written.
    expect(KeyPrefix::make('foo'))->toBe('qm:{staging}:foo');
});

it('leaves the prefix untagged when redis_cluster is off', function (): void {
    config()->set('queue-insights.redis_cluster', false);

    expect(KeyPrefix::make('foo'))->toBe('qmtest:foo');
});
