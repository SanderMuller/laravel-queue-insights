<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\PendingPayloadSnapshot;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fakePayload(array $overrides = []): array
{
    $cmd = 'O:11:"App\\Jobs\\X":1:{s:5:"email";s:13:"a@example.com";}';

    /** @var array<string, mixed> */
    return array_replace([
        'uuid' => 'fake-uuid',
        'displayName' => 'App\\Jobs\\X',
        'maxTries' => 3,
        'timeout' => 60,
        'backoff' => [1, 5, 10],
        'data' => [
            'commandName' => 'App\\Jobs\\X',
            'command' => $cmd,
        ],
    ], $overrides);
}

/**
 * Decode a payload_body field, guarding the `string|false` return of
 * json_encode. The build() contract guarantees a string when the key is
 * present, but PHPStan can't see that across the array boundary.
 */
function decodePayloadBody(mixed $body): mixed
{
    return is_string($body) ? json_decode($body, true) : null;
}

it('returns empty array when mode is off', function (): void {
    expect(PendingPayloadSnapshot::build(fakePayload(), 'off', [], 2048, 4096))->toBe([]);
});

it('returns empty array when payload is null', function (): void {
    expect(PendingPayloadSnapshot::build(null, 'full', [], 2048, 4096))->toBe([]);
});

it('writes only metadata fields in metadata mode — no payload_body', function (): void {
    $fields = PendingPayloadSnapshot::build(fakePayload(), 'metadata', [], 2048, 4096);

    expect($fields)
        ->toHaveKey('payload_displayName', 'App\\Jobs\\X')
        ->toHaveKey('payload_maxTries', 3)
        ->toHaveKey('payload_timeout', 60)
        // backoff is an int list — encoded as JSON for round-trippability.
        ->toHaveKey('payload_backoff', '[1,5,10]')
        ->not->toHaveKey('payload_body');
});

it('writes payload_body in full mode alongside metadata', function (): void {
    $fields = PendingPayloadSnapshot::build(fakePayload(), 'full', [], 2048, 4096);

    expect($fields)->toHaveKey('payload_body');
    $body = decodePayloadBody($fields['payload_body'] ?? null);
    expect($body)
        ->toBeArray()
        ->toHaveKey('uuid', 'fake-uuid')
        ->toHaveKey('displayName', 'App\\Jobs\\X');
});

it('redacts top-level keys matching the redact-keys regex list', function (): void {
    $payload = fakePayload(['password' => 'hunter2', 'api_key' => 'sk_live_1', 'safe' => 'ok']);

    $fields = PendingPayloadSnapshot::build($payload, 'full', ['password', 'api_?key'], 2048, 4096);

    $body = decodePayloadBody($fields['payload_body'] ?? null);
    expect($body)
        ->toHaveKey('password', '[REDACTED]')
        ->toHaveKey('api_key', '[REDACTED]')
        ->toHaveKey('safe', 'ok');
});

it('keeps PHP-serialized command blobs intact even past maxFieldBytes', function (): void {
    // The SerializedCommandReader pipeline + nested-data ValueParser
    // need the bytes intact to decode the instance properties on the
    // modal. Truncation would silently produce an invalid serialized
    // string that ends up as a wall of garbage in the right column.
    $longCmd = 'O:11:"App\\Jobs\\X":1:{s:4:"blob";s:1000:"' . str_repeat('A', 1000) . '";}';
    $payload = fakePayload(['data' => ['commandName' => 'App\\Jobs\\X', 'command' => $longCmd]]);

    $fields = PendingPayloadSnapshot::build($payload, 'full', [], 128, 16384);

    $body = decodePayloadBody($fields['payload_body'] ?? null);
    expect($body['data']['command'])->toBe($longCmd);
});

it('flags a closure payload as not-persisted with a reason', function (): void {
    $payload = fakePayload(['data' => ['commandName' => 'Illuminate\\Queue\\CallQueuedClosure', 'command' => 'irrelevant']]);

    $fields = PendingPayloadSnapshot::build($payload, 'full', [], 2048, 4096);

    expect($fields)
        ->toBe(['payload_note' => 'payload_not_persisted', 'payload_reason' => 'closure_or_encrypted']);
});

it('flags an encrypted command (non O:/C: opener) as not-persisted', function (): void {
    $payload = fakePayload(['data' => ['commandName' => 'App\\Jobs\\X', 'command' => 'eyJpdiI6...encrypted...']]);

    $fields = PendingPayloadSnapshot::build($payload, 'full', [], 2048, 4096);

    expect($fields['payload_note'])->toBe('payload_not_persisted');
    expect($fields['payload_reason'])->toBe('closure_or_encrypted');
});

it('returns size-exceeded error when the encoded body exceeds maxPayloadBytes', function (): void {
    $payload = fakePayload(['huge' => str_repeat('A', 5000)]);

    $fields = PendingPayloadSnapshot::build($payload, 'full', [], 8192, 1024);

    expect($fields['payload_error'])->toBe('payload_too_large');
    expect($fields)->toHaveKey('payload_size');
    expect((int) $fields['payload_size'])->toBeGreaterThan(1024);
    expect($fields)->not->toHaveKey('payload_body');
});
