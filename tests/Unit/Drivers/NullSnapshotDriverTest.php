<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Drivers\NullSnapshotDriver;

it('returns 0 depth and null for in-flight / delayed', function (): void {
    $driver = new NullSnapshotDriver();

    expect($driver->depth('default'))->toBe(0)
        ->and($driver->inFlight('default'))->toBeNull()
        ->and($driver->delayed('default'))->toBeNull();
});

it('produces canonical keys via the shared helper', function (): void {
    $driver = new NullSnapshotDriver();

    expect($driver->canonicalKey('https://sqs.eu-west-1.amazonaws.com/123/my-q'))->toBe('my-q')
        ->and($driver->canonicalKey('foo/bar'))->toBe('foo_bar');
});
