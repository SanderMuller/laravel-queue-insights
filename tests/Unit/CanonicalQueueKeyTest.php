<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Tests\Support\CloudQueueConfig;

it('normalizes plain names verbatim', function (): void {
    expect(CanonicalQueueKey::from('my-queue'))->toBe('my-queue')
        ->and(CanonicalQueueKey::from('default'))->toBe('default')
        ->and(CanonicalQueueKey::from('high_priority'))->toBe('high_priority');
});

it('treats an SQS URL as equivalent to its queue name', function (): void {
    $url = 'https://sqs.eu-west-1.amazonaws.com/123456789012/my-q';
    $name = 'my-q';

    expect(CanonicalQueueKey::from($url))
        ->toBe(CanonicalQueueKey::from($name))
        ->and(CanonicalQueueKey::from($url))->toBe('my-q');
});

it('accepts http URLs (not only https)', function (): void {
    expect(CanonicalQueueKey::from('http://example.test/acct/my-q'))->toBe('my-q');
});

it('replaces disallowed characters with underscore', function (): void {
    expect(CanonicalQueueKey::from('foo/bar'))->toBe('foo_bar')
        ->and(CanonicalQueueKey::from('foo.bar'))->toBe('foo_bar')
        ->and(CanonicalQueueKey::from('weird queue!'))->toBe('weird_queue_');
});

it('collapses "foo/bar" and "foo_bar" to the same canonical key', function (): void {
    expect(CanonicalQueueKey::from('foo/bar'))
        ->toBe(CanonicalQueueKey::from('foo_bar'))
        ->toBe('foo_bar');
});

it('rejects empty input', function (): void {
    CanonicalQueueKey::from('');
})->throws(InvalidArgumentException::class);

it('rejects whitespace-only input', function (): void {
    CanonicalQueueKey::from("   \t\n");
})->throws(InvalidArgumentException::class);

it('rejects input that normalizes to an empty key via trailing slash', function (): void {
    CanonicalQueueKey::from('https://sqs.example/acct/');
})->throws(InvalidArgumentException::class);

it('fromOrDefault resolves the connection-configured queue when input is empty', function (): void {
    config()->set('queue.connections.sqs.queue', 'staging_default');

    expect(CanonicalQueueKey::fromOrDefault('', 'sqs'))->toBe('staging_default');
});

it('fromOrDefault canonicalises an SQS URL stored as the connection default', function (): void {
    config()->set('queue.connections.sqs.queue', 'https://sqs.eu-west-1.amazonaws.com/123/staging-q');

    expect(CanonicalQueueKey::fromOrDefault('', 'sqs'))->toBe('staging-q');
});

it('fromOrDefault falls back to the literal "default" when no connection default is configured', function (): void {
    config()->set('queue.connections.weird', []);

    expect(CanonicalQueueKey::fromOrDefault('', 'weird'))->toBe('default');
});

it('fromOrDefault falls back to the literal "default" when connection name is empty', function (): void {
    expect(CanonicalQueueKey::fromOrDefault('', ''))->toBe('default');
});

it('fromOrDefault delegates to from() when input is non-empty', function (): void {
    config()->set('queue.connections.sqs.queue', 'should-not-be-used');

    expect(CanonicalQueueKey::fromOrDefault('explicit-queue', 'sqs'))->toBe('explicit-queue');
});

it('fromOrDefault treats whitespace-only input as empty', function (): void {
    config()->set('queue.connections.sqs.queue', 'staging_default');

    expect(CanonicalQueueKey::fromOrDefault("\t  \n", 'sqs'))->toBe('staging_default');
});

describe('forConnection', function (): void {
    beforeEach(function (): void {
        // Laravel Cloud: real connection nested, every physical queue suffixed.
        config()->set('queue.connections.cloud', CloudQueueConfig::make());
        // Plain SQS with SQS_SUFFIX set: suffix at the top level.
        config()->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'queue' => 'default',
            'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123',
            'suffix' => '-production',
        ]);
        config()->set('queue.connections.redis', ['driver' => 'redis', 'queue' => 'default']);
    });

    it('strips the suffix a nested cloud connection carries', function (): void {
        expect(CanonicalQueueKey::forConnection('stats-abc123', 'cloud'))->toBe('stats')
            ->and(CanonicalQueueKey::forConnection(CloudQueueConfig::url('stats'), 'cloud'))->toBe('stats');
    });

    it('strips the suffix a plain sqs connection carries', function (): void {
        expect(CanonicalQueueKey::forConnection('default-production', 'sqs'))->toBe('default')
            ->and(CanonicalQueueKey::forConnection('https://sqs.eu-west-1.amazonaws.com/123/default-production', 'sqs'))
            ->toBe('default');
    });

    it('collapses the logical and physical names of one queue onto a single key', function (): void {
        // The invariant the producer/worker split hinges on: JobQueued only
        // ever carries the logical name, SqsJob::getQueue() only the URL.
        expect(CanonicalQueueKey::forConnection('stats', 'cloud'))
            ->toBe(CanonicalQueueKey::forConnection(CloudQueueConfig::url('stats'), 'cloud'));
    });

    it('keeps the suffix out of the .fifo marker on the way back', function (): void {
        expect(CanonicalQueueKey::forConnection('orders-abc123.fifo', 'cloud'))->toBe('orders_fifo');
    });

    it('leaves a name that never carried the suffix alone', function (): void {
        expect(CanonicalQueueKey::forConnection('stats', 'cloud'))->toBe('stats')
            // Partial match on the suffix is not a match.
            ->and(CanonicalQueueKey::forConnection('stats-abc', 'cloud'))->toBe('stats-abc');
    });

    it('is identical to from() for a connection with no suffix', function (): void {
        expect(CanonicalQueueKey::forConnection('default', 'redis'))->toBe('default')
            ->and(CanonicalQueueKey::forConnection('default-abc123', 'redis'))->toBe('default-abc123')
            ->and(CanonicalQueueKey::forConnection('default', 'not-configured-at-all'))->toBe('default');
    });

    it('rejects an empty queue the same way from() does', function (): void {
        expect(fn (): string => CanonicalQueueKey::forConnection('', 'cloud'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('resolves the connection default before stripping', function (): void {
        // fromOrDefault's config fallback is already logical; stripping must
        // not mangle it.
        expect(CanonicalQueueKey::fromOrDefault('', 'cloud'))->toBe('default')
            ->and(CanonicalQueueKey::fromOrDefault(CloudQueueConfig::url('mail'), 'cloud'))->toBe('mail');
    });
});

it('resolves the suffix through the alias map when the canonical name is not a config key', function (): void {
    // `connection_aliases` may rename a connection to a name that has no
    // `queue.connections.*` entry; listeners hand that canonical name over.
    config()->set('queue.connections.cloud', CloudQueueConfig::make());
    config()->set('queue-insights.connection_aliases', ['cloud' => 'managed']);

    expect(CanonicalQueueKey::forConnection(CloudQueueConfig::url('stats'), 'managed'))->toBe('stats')
        ->and(CanonicalQueueKey::forConnection('stats', 'managed'))->toBe('stats');
});

it('ignores an alias entry pointing at a different connection', function (): void {
    config()->set('queue.connections.cloud', CloudQueueConfig::make());
    config()->set('queue-insights.connection_aliases', ['cloud' => 'managed']);

    expect(CanonicalQueueKey::forConnection('stats-abc123', 'other'))->toBe('stats-abc123');
});
