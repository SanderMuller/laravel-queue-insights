<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Events\JobProcessed;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Support\Sanitizers\KeyRedactingSanitizer;
use SanderMuller\QueueInsights\Support\Sanitizers\MetadataOnlySanitizer;

/**
 * @param  array<array-key, mixed>  $payload
 */
function makeJobEvent(array $payload, string $connection = 'sync', string $queue = 'default'): JobProcessed
{
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $commandName = is_string($data['commandName'] ?? null) ? $data['commandName'] : 'TestJob';

    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('uuid')->andReturn('uuid-test');
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('resolveName')->andReturn($commandName);

    return new JobProcessed($connection, $job);
}

describe('MetadataOnlySanitizer', function (): void {
    it('returns only allow-listed metadata fields', function (): void {
        $event = makeJobEvent([
            'displayName' => 'App\\Jobs\\SendEmail',
            'maxTries' => 3,
            'timeout' => 30,
            'backoff' => 5,
            'data' => [
                'commandName' => 'App\\Jobs\\SendEmail',
                'command' => 'O:20:"App\\Jobs\\SendEmail":0:{}',
                'password' => 'hunter2',
            ],
        ]);

        $out = (new MetadataOnlySanitizer())->sanitize($event);

        expect($out)->toBe([
            'displayName' => 'App\\Jobs\\SendEmail',
            'maxTries' => 3,
            'timeout' => 30,
            'backoff' => 5,
        ]);
    });

    it('returns an empty array when the job has no standard metadata', function (): void {
        $event = makeJobEvent([
            'data' => ['commandName' => 'App\\Jobs\\Bare', 'command' => 'O:0:"":0:{}'],
        ]);

        $out = (new MetadataOnlySanitizer())->sanitize($event);

        expect($out)->toBeEmpty();
    });

    it('emits a payload_not_persisted note for CallQueuedClosure', function (): void {
        $event = makeJobEvent([
            'data' => ['commandName' => CallQueuedClosure::class, 'command' => 'whatever'],
        ]);

        expect((new MetadataOnlySanitizer())->sanitize($event))
            ->toBe(['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted']);
    });
});

describe('KeyRedactingSanitizer', function (): void {
    $redactKeys = ['password', 'token', 'secret', 'api_?key', 'authorization'];

    it('redacts matching keys at any depth', function () use ($redactKeys): void {
        $event = makeJobEvent([
            'data' => [
                'commandName' => 'App\\Jobs\\Login',
                'command' => 'O:10:"App\\Login":0:{}',
                'password' => 'hunter2',
                'nested' => [
                    'token' => 'abc',
                    'safe' => 'visible',
                    'Authorization' => 'Bearer XYZ',
                ],
                'api_key' => 'leak',
                'apikey' => 'leak2',
            ],
        ]);

        $out = (new KeyRedactingSanitizer($redactKeys))->sanitize($event);

        expect($out)->toHaveKey('body');

        $body = $out['body'] ?? null;
        $decoded = json_decode(is_string($body) ? $body : '{}', true);

        expect($decoded['data']['password'])->toBe('[REDACTED]')
            ->and($decoded['data']['nested'])->toMatchArray(['token' => '[REDACTED]', 'Authorization' => '[REDACTED]', 'safe' => 'visible'])
            ->and($decoded['data'])->toMatchArray(['api_key' => '[REDACTED]', 'apikey' => '[REDACTED]']);
    });

    it('truncates oversized string fields', function () use ($redactKeys): void {
        $long = str_repeat('x', 5000);

        $event = makeJobEvent([
            'data' => ['commandName' => 'App\\Jobs\\Foo', 'command' => 'O:5:"Foo":0:{}', 'note' => $long],
        ]);

        $out = (new KeyRedactingSanitizer($redactKeys, maxFieldBytes: 2048))->sanitize($event);

        $body = $out['body'] ?? null;
        $decoded = json_decode(is_string($body) ? $body : '{}', true);

        $note = $decoded['data']['note'] ?? '';
        expect($note)->toEndWith('…[truncated]')
            ->and(mb_strlen(is_string($note) ? $note : ''))->toBeLessThan(5000);
    });

    it('preserves PHP-serialized blobs intact even when they exceed maxFieldBytes', function () use ($redactKeys): void {
        // Build a serialized blob bigger than maxFieldBytes — the structured-payload
        // extractor in the modal needs an intact blob to unserialize, so truncation
        // would render the Job instance panel useless.
        $bigSerialized = serialize((object) array_fill_keys(
            array_map(fn (int $i): string => "field_{$i}", range(1, 100)),
            'value',
        ));
        expect(strlen($bigSerialized))->toBeGreaterThan(2048);

        $event = makeJobEvent([
            'data' => ['commandName' => 'App\\Jobs\\Big', 'command' => $bigSerialized],
        ]);

        $out = (new KeyRedactingSanitizer($redactKeys, maxFieldBytes: 2048, maxPayloadBytes: 65536))->sanitize($event);
        $body = $out['body'] ?? null;
        $decoded = json_decode(is_string($body) ? $body : '{}', true);

        expect($decoded['data']['command'] ?? '')->toBe($bigSerialized)
            ->and($decoded['data']['command'] ?? '')->not->toEndWith('…[truncated]');
    });

    it('hard-caps payloads that exceed maxPayloadBytes', function () use ($redactKeys): void {
        $event = makeJobEvent([
            'data' => ['commandName' => 'Foo', 'command' => 'O:3:"Foo":0:{}', 'blob' => str_repeat('z', 400)],
            'bigArray' => array_fill(0, 500, 'xxxx'),
        ]);

        $out = (new KeyRedactingSanitizer($redactKeys, maxPayloadBytes: 1024))->sanitize($event);

        expect($out)->toHaveKey('error')
            ->and($out['error'])->toBe('payload_too_large');
    });

    it('returns payload_not_persisted for CallQueuedClosure payloads', function () use ($redactKeys): void {
        $event = makeJobEvent([
            'data' => ['commandName' => CallQueuedClosure::class, 'command' => 'x'],
        ]);

        expect((new KeyRedactingSanitizer($redactKeys))->sanitize($event))
            ->toBe(['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted']);
    });

    it('returns payload_not_persisted when the command body is not PHP-serialized', function () use ($redactKeys): void {
        $event = makeJobEvent([
            'data' => ['commandName' => 'App\\Jobs\\Encrypted', 'command' => 'base64-encrypted-ciphertext'],
        ]);

        expect((new KeyRedactingSanitizer($redactKeys))->sanitize($event))
            ->toBe(['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted']);
    });
});
