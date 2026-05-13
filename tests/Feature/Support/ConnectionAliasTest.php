<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\ConnectionAlias;

beforeEach(function (): void {
    config()->set('queue-insights.connection_aliases', []);
});

it('returns the input unchanged when no alias is configured', function (): void {
    expect(ConnectionAlias::canonical('redis'))->toBe('redis');
});

it('resolves a mapped alias to its canonical target', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(ConnectionAlias::canonical('redis'))->toBe('redis-staging');
});

it('passes through identity mappings', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis-staging' => 'redis-staging']);

    expect(ConnectionAlias::canonical('redis-staging'))->toBe('redis-staging');
});

it('returns the input unchanged when not present in the map', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(ConnectionAlias::canonical('sqs'))->toBe('sqs');
});

it('returns an empty string for an empty input', function (): void {
    expect(ConnectionAlias::canonical(''))
        ->toBeEmpty();
});

it('falls through to the input when the mapped value is not a non-empty string', function (): void {
    // Validator should reject these at boot, but the helper is defensive.
    config()->set('queue-insights.connection_aliases', ['redis' => '']);

    expect(ConnectionAlias::canonical('redis'))->toBe('redis');
});
