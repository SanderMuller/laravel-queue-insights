<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\Detectors\SnapshotErroredDetector;

beforeEach(function (): void {
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.rules.snapshot_errored.enabled', true);
});

it('SnapshotErroredDetector sanitises multi-line exception messages in the description while keeping the raw payload in context', function (): void {
    $raw = "Connection refused\n  stacktrace line 1\n  stacktrace line 2";

    $detector = new SnapshotErroredDetector();
    $issue = $detector->evaluate('redis', 'default', $raw) ?? throw new RuntimeException('detector returned null');

    // Description is the operator-facing string — sanitised single-line.
    expect($issue->description)->toBe('Latest snapshot for redis:default failed: Connection refused stacktrace line 1 stacktrace line 2')
        // Typed `SnapshotErrored::$errorMessage` event payload is built off
        // context['error_message'] — must stay raw so host listeners
        // forwarding to Sentry / external systems still get the full text.
        ->and($issue->context['error_message'])->toBe($raw);
});

it('SnapshotErroredDetector substitutes a description placeholder when the message sanitises to empty', function (): void {
    $detector = new SnapshotErroredDetector();
    $issue = $detector->evaluate('redis', 'default', "   \n\t  ") ?? throw new RuntimeException('detector returned null');

    expect($issue->description)->toBe('Latest snapshot for redis:default failed: (no message)')
        // Raw whitespace-only message is preserved in context — the
        // operator-facing placeholder lives in description only.
        ->and($issue->context['error_message'])->toBe("   \n\t  ");
});

it('SnapshotErroredDetector returns null when the message is not a string (no error key present)', function (): void {
    $detector = new SnapshotErroredDetector();

    expect($detector->evaluate('redis', 'default', null))->toBeNull()
        ->and($detector->evaluate('redis', 'default', false))->toBeNull();
});
