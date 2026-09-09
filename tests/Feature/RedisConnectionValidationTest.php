<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\ConfigValidator;

beforeEach(function (): void {
    // Testbench ships no `database.redis.connections`; a real app always has
    // some, which is the shape this validator is written for.
    config()->set('database.redis.connections', [
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);
    config()->set('database.redis.clusters', []);
});

it('accepts a connection defined under database.redis.connections', function (): void {
    ConfigValidator::validateRedisConnectionName('default', 'redis_connection');
})->throwsNoExceptions();

it('accepts a connection defined as a cluster', function (): void {
    config()->set('database.redis.clusters.insights', [['host' => '127.0.0.1']]);

    ConfigValidator::validateRedisConnectionName('insights', 'redis_connection');
})->throwsNoExceptions();

it('names the unknown connection and lists the known ones', function (): void {
    ConfigValidator::validateRedisConnectionName('queue-insights', 'redis_connection');
})->throws(QueueInsightsConfigException::class, 'names the Redis connection "queue-insights"');

it('rejects an empty connection name', function (): void {
    ConfigValidator::validateRedisConnectionName('', 'redis_connection');
})->throws(QueueInsightsConfigException::class, 'must be a non-empty Redis connection name');

it('stays quiet when the host configures no redis at all', function (): void {
    config()->set('database.redis.connections', []);
    config()->set('database.redis.clusters', []);

    ConfigValidator::validateRedisConnectionName('whatever', 'redis_connection');
})->throwsNoExceptions();

it('validates the chain_lineage override against the same list', function (): void {
    ConfigValidator::validateChainLineage(['redis_connection' => 'nope']);
})->throws(QueueInsightsConfigException::class, 'queue-insights.chain_lineage.redis_connection names the Redis connection "nope"');
