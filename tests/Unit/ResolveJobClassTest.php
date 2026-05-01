<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedClosure;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Support\ResolveJobClass;

/**
 * @param  array<array-key, mixed>  $payload
 */
function makeResolvableJob(string $name, array $payload = []): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('payload')->andReturn($payload);

    return $job;
}

/**
 * @param  array<array-key, mixed>  $payload
 */
function makeUnresolvableJob(array $payload = []): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andThrow(new RuntimeException('cannot resolve'));
    $job->shouldReceive('payload')->andReturn($payload);

    return $job;
}

it('returns the resolved class name for ordinary jobs', function (): void {
    $job = makeResolvableJob('App\\Jobs\\SendEmail', [
        'data' => ['commandName' => 'App\\Jobs\\SendEmail', 'command' => 'O:18:"App\\Jobs\\SendEmail":0:{}'],
    ]);

    expect((new ResolveJobClass())->from($job, 'redis', 'default'))->toBe('App\\Jobs\\SendEmail');
});

it('buckets CallQueuedClosure under Closure@{connection}:{queue}', function (): void {
    $job = makeResolvableJob(CallQueuedClosure::class, [
        'data' => ['commandName' => CallQueuedClosure::class, 'command' => 'x'],
    ]);

    expect((new ResolveJobClass())->from($job, 'redis', 'default'))->toBe('Closure@redis:default');
});

it('returns Encrypted@{connection}:{queue} for encrypted-looking payloads', function (): void {
    $job = makeResolvableJob('App\\Jobs\\Secret', [
        'data' => ['commandName' => 'App\\Jobs\\Secret', 'command' => 'base64cipherblob'],
    ]);

    expect((new ResolveJobClass())->from($job, 'sqs', 'default'))->toBe('Encrypted@sqs:default');
});

it('returns Unresolved when resolveName throws and payload looks normal', function (): void {
    $job = makeUnresolvableJob([
        'data' => ['commandName' => 'App\\Jobs\\Ok', 'command' => 'O:0:"":0:{}'],
    ]);

    expect((new ResolveJobClass())->from($job, 'redis', 'default'))->toBe('Unresolved');
});

it('returns Encrypted even when resolveName throws, if the payload looks encrypted', function (): void {
    $job = makeUnresolvableJob([
        'data' => ['commandName' => 'Gone', 'command' => 'ciphered-blob'],
    ]);

    expect((new ResolveJobClass())->from($job, 'redis', 'high'))->toBe('Encrypted@redis:high');
});
