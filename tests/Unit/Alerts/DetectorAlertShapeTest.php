<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\Detectors\SnapshotErroredDetector;

beforeEach(function (): void {
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.rules.snapshot_errored.enabled', true);
});

it('SnapshotErroredDetector sanitises multi-line exception messages into a single line', function (): void {
    $detector = new SnapshotErroredDetector();
    $issue = $detector->evaluate('redis', 'default', "Connection refused\n  stacktrace line 1\n  stacktrace line 2");

    expect($issue)->not->toBeNull();
    assert($issue !== null);
    expect($issue->description)->toBe('Latest snapshot for redis:default failed: Connection refused stacktrace line 1 stacktrace line 2')
        ->and($issue->context['error_message'])->toBe('Connection refused stacktrace line 1 stacktrace line 2');
});

it('SnapshotErroredDetector substitutes a placeholder when the message sanitises to empty', function (): void {
    $detector = new SnapshotErroredDetector();
    $issue = $detector->evaluate('redis', 'default', "   \n\t  ");

    expect($issue)->not->toBeNull();
    assert($issue !== null);
    expect($issue->context['error_message'])->toBe('(no message)')
        ->and($issue->description)->toBe('Latest snapshot for redis:default failed: (no message)');
});

it('SnapshotErroredDetector returns null when the message is not a string (no error key present)', function (): void {
    $detector = new SnapshotErroredDetector();

    expect($detector->evaluate('redis', 'default', null))->toBeNull()
        ->and($detector->evaluate('redis', 'default', false))->toBeNull();
});
