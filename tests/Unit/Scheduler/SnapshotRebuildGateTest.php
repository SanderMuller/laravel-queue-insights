<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Scheduler\SnapshotRebuildGate;

it('matches scheduler and package commands by default', function (string $command): void {
    expect(SnapshotRebuildGate::matches($command))->toBeTrue();
})->with([
    'schedule:run',
    'schedule:work',
    'schedule:test',
    'queue-insights:schedule:list',
    'queue-insights:snapshot',
]);

it('ignores unrelated commands', function (?string $command): void {
    expect(SnapshotRebuildGate::matches($command))->toBeFalse();
})->with([
    'migrate',
    'translations:audit',
    'queue:work',
    'scheduled:thing',
    '',
    null,
]);

it('honours a host-configured command list', function (): void {
    config()->set('queue-insights.scheduler.snapshot_rebuild_commands', ['cron:run', 'ops:*']);

    expect(SnapshotRebuildGate::matches('cron:run'))->toBeTrue()
        ->and(SnapshotRebuildGate::matches('ops:tick'))->toBeTrue()
        ->and(SnapshotRebuildGate::matches('schedule:run'))->toBeFalse();
});

it('matches nothing when the host configures an empty list', function (): void {
    config()->set('queue-insights.scheduler.snapshot_rebuild_commands', []);

    expect(SnapshotRebuildGate::matches('schedule:run'))->toBeFalse();
});

it('ignores unusable entries in the configured list', function (): void {
    config()->set('queue-insights.scheduler.snapshot_rebuild_commands', ['', 42, 'cron:tick']);

    expect(SnapshotRebuildGate::matches('cron:tick'))->toBeTrue()
        ->and(SnapshotRebuildGate::matches('schedule:run'))->toBeFalse();
});

it('falls back to the defaults when the key is absent entirely', function (): void {
    config()->set('queue-insights.scheduler', ['enabled' => true]);

    expect(SnapshotRebuildGate::matches('schedule:run'))->toBeTrue();
});
