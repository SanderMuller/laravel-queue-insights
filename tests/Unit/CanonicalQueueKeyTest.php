<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\CanonicalQueueKey;

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
