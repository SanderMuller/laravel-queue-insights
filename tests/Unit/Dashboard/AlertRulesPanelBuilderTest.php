<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Dashboard\AlertRulesPanelBuilder;
use SanderMuller\QueueInsights\Enums\AlertSeverity;

beforeEach(function (): void {
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.thresholds', []);
    config()->set('queue-insights.alerts.rules', [
        'depth' => [
            'enabled' => true,
            'thresholds' => [
                ['connection' => 'redis', 'queue' => 'default', 'depth' => 1000, 'severity' => AlertSeverity::Warning->value],
                ['connection' => 'sqs', 'queue' => 'work', 'depth' => 5000, 'severity' => AlertSeverity::Critical->value],
                ['connection' => 'redis', 'queue' => 'high', 'depth' => 2000, 'severity' => AlertSeverity::Critical->value],
            ],
        ],
    ]);
});

// AlertRulesPanelBuilder::rules() iterates a fixed order
// (`['depth', 'stalled', ...]`), so depth is always the first entry. We
// index by 0 directly rather than `firstWhere('key', 'depth')` because the
// latter widens to `array|null` and forces a runtime null-check that adds
// noise to the assertion.
function alertRuleDepthValue(AlertRulesPanelBuilder $builder, ?string $scope = null): string
{
    $panel = $builder->build($scope);
    $depthRule = $panel['rules'][0];
    expect($depthRule['key'])->toBe('depth');

    return $depthRule['params'][0]['value'];
}

it('build keeps every depth threshold when scope is null', function (): void {
    $value = alertRuleDepthValue(new AlertRulesPanelBuilder());

    expect($value)->toContain('redis:default')
        ->and($value)->toContain('sqs:work')
        ->and($value)->toContain('redis:high');
});

it('build filters depth thresholds to the scoped connection', function (): void {
    $value = alertRuleDepthValue(new AlertRulesPanelBuilder(), 'redis');

    expect($value)->toContain('redis:default')
        ->and($value)->toContain('redis:high')
        ->and($value)->not->toContain('sqs:work');
});

it('build renders "(none)" for the depth row when no thresholds match the scope', function (): void {
    expect(alertRuleDepthValue(new AlertRulesPanelBuilder(), 'unknown'))->toBe('(none)');
});

/**
 * @param  array{rules: list<array{key: string, firing_count: int, firing_severity: ?AlertSeverity, firing_issues: list<array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}>}>, ...}  $panel
 * @return array{key: string, firing_count: int, firing_severity: ?AlertSeverity, firing_issues: list<array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}>}
 */
function alertRuleRow(array $panel, string $key): array
{
    foreach ($panel['rules'] as $row) {
        if ($row['key'] === $key) {
            return $row;
        }
    }

    throw new RuntimeException("Rule {$key} not in panel");
}

function makeIssue(string $rule, AlertSeverity $severity, string $connection = 'redis', string $queue = 'default', ?string $jobClass = null): Issue
{
    return new Issue(
        rule: $rule,
        severity: $severity,
        connection: $connection,
        queue: $queue,
        jobClass: $jobClass,
        title: 'x',
        description: 'x',
        context: [],
        detectedAt: 0,
    );
}

it('build defaults firing_count to 0 and firing_severity to null when no issues passed', function (): void {
    $panel = (new AlertRulesPanelBuilder())->build();
    $depth = alertRuleRow($panel, 'depth');

    expect($depth['firing_count'])->toBe(0)
        ->and($depth['firing_severity'])->toBeNull();
});

it('build keeps class-scoped firing counts even when a connection scope is set, mirroring the alerts strip', function (): void {
    $issues = [
        // Class-scoped issues carry connection='' by design and survive
        // ActiveIssuesProvider::get($scope). The panel must mirror that —
        // suppressing them here would contradict the red strip above.
        makeIssue('failure_rate', AlertSeverity::Critical, '', '', 'App\\Jobs\\SendEmail'),
        makeIssue('slow_p95', AlertSeverity::Warning, '', '', 'App\\Jobs\\BuildReport'),
        makeIssue('depth', AlertSeverity::Warning, 'redis', 'default'),
    ];

    $panel = (new AlertRulesPanelBuilder())->build('redis', $issues);

    expect(alertRuleRow($panel, 'failure_rate')['firing_count'])->toBe(1)
        ->and(alertRuleRow($panel, 'slow_p95')['firing_count'])->toBe(1)
        ->and(alertRuleRow($panel, 'depth')['firing_count'])->toBe(1);
});

it('build counts active issues per rule and tracks worst severity', function (): void {
    $issues = [
        makeIssue('depth', AlertSeverity::Warning, 'redis', 'default'),
        makeIssue('depth', AlertSeverity::Critical, 'redis', 'high'),
        makeIssue('depth', AlertSeverity::Warning, 'redis', 'low'),
        makeIssue('stalled', AlertSeverity::Warning, 'redis', 'default'),
    ];

    $panel = (new AlertRulesPanelBuilder())->build(null, $issues);

    $depth = alertRuleRow($panel, 'depth');
    expect($depth['firing_count'])->toBe(3)
        ->and($depth['firing_severity'])->toBe(AlertSeverity::Critical);

    $stalled = alertRuleRow($panel, 'stalled');
    expect($stalled['firing_count'])->toBe(1)
        ->and($stalled['firing_severity'])->toBe(AlertSeverity::Warning);

    $oldest = alertRuleRow($panel, 'oldest_pending');
    expect($oldest['firing_count'])->toBe(0)
        ->and($oldest['firing_severity'])->toBeNull();
});

it('build exposes flattened firing issues per rule for sub-row rendering', function (): void {
    $issues = [
        makeIssue('depth', AlertSeverity::Warning, 'redis', 'default'),
        makeIssue('depth', AlertSeverity::Critical, 'sqs', 'reports'),
        makeIssue('failure_rate', AlertSeverity::Critical, '', '', 'App\\Jobs\\SendEmail'),
    ];

    $panel = (new AlertRulesPanelBuilder())->build(null, $issues);

    $depth = alertRuleRow($panel, 'depth');
    expect($depth['firing_issues'])->toHaveCount(2)
        ->and($depth['firing_issues'][0]['target'])->toBe('redis:default')
        ->and($depth['firing_issues'][0]['target_type'])->toBe('queue')
        ->and($depth['firing_issues'][1]['target'])->toBe('sqs:reports')
        ->and($depth['firing_issues'][1]['severity'])->toBe(AlertSeverity::Critical);

    $failure = alertRuleRow($panel, 'failure_rate');
    expect($failure['firing_issues'])->toHaveCount(1)
        ->and($failure['firing_issues'][0]['target'])->toBe('App\\Jobs\\SendEmail')
        ->and($failure['firing_issues'][0]['target_type'])->toBe('class');
});

function alertChannelDetail(string $key, AlertRulesPanelBuilder $builder): string
{
    foreach ($builder->build()['channels'] as $channel) {
        if ($channel['key'] === $key) {
            return $channel['detail'];
        }
    }

    throw new RuntimeException("Channel {$key} not in panel");
}

it('channels panel includes a sentry row that reads disabled by default', function (): void {
    config()->set('queue-insights.alerts.channels.sentry.enabled', false);

    expect(alertChannelDetail('sentry', new AlertRulesPanelBuilder()))->toBe('disabled');
});

it('channels detail reports hub-not-configured when sentry is enabled but no client is bound', function (): void {
    if (! function_exists('Sentry\captureMessage')) {
        $this->markTestSkipped('sentry/sentry not installed; this state is reported as SDK not installed instead');
    }

    // SDK loaded (dev dep) but the default test env binds no Sentry client.
    config()->set('queue-insights.alerts.channels.sentry.enabled', true);

    expect(alertChannelDetail('sentry', new AlertRulesPanelBuilder()))->toBe('hub not configured');
});

it('channels detail reports capturing-to-host-hub when sentry is enabled and a client is bound', function (): void {
    if (! function_exists('Sentry\captureMessage')) {
        $this->markTestSkipped('sentry/sentry not installed; SDK-present detail unreachable');
    }

    config()->set('queue-insights.alerts.channels.sentry.enabled', true);

    withBoundSentryHub(function (): void {
        expect(alertChannelDetail('sentry', new AlertRulesPanelBuilder()))->toBe('capturing to host hub');
    });
});

it('channels detail surfaces the configured slack channel label', function (): void {
    config()->set('queue-insights.alerts.channels.slack.enabled', true);
    config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.slack.com/services/T0DEMO/B0DEMO/secret');
    config()->set('queue-insights.alerts.channels.slack.channel', '#queue-alerts');

    expect(alertChannelDetail('slack', new AlertRulesPanelBuilder()))->toBe('channel: #queue-alerts');
});

it('channels detail falls back to a non-secret webhook hash when no channel label is set', function (): void {
    $url = 'https://hooks.slack.com/services/T0WORK000/B0WORK000/abcdEFGHijklMNOPqrstUVWXyz12';

    config()->set('queue-insights.alerts.channels.slack.enabled', true);
    config()->set('queue-insights.alerts.channels.slack.webhook_url', $url);
    config()->set('queue-insights.alerts.channels.slack.channel', '');

    $detail = alertChannelDetail('slack', new AlertRulesPanelBuilder());

    // Stable 8-hex-char hash of the URL — non-secret, deterministic.
    expect($detail)->toBe('webhook: ' . substr(hash('sha256', $url), 0, 8));

    // Hard guarantee: no substring of the secret token leaks into the
    // rendered detail. Catches accidental regressions to suffix/affix
    // fingerprinting that would expose secret material in screenshots.
    foreach (['abcd', 'EFGH', 'ijkl', 'MNOP', 'qrst', 'UVWX', 'yz12'] as $secretSlice) {
        expect(str_contains($detail, $secretSlice))->toBeFalse();
    }
});
