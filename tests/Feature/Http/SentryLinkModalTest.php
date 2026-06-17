<?php declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * The "View in Sentry" button + Markdown line in the failed-job modal. The
 * modal is a blade component fed props directly via Blade::render (mirrors
 * FailureContextModalTest). The link is built from the sentry-laravel tracing
 * data in the payload by SentryTraceLink, gated on a configured org slug.
 *
 * @param  array<string, mixed>  $payload
 */
function renderSentryFailedModal(array $payload): string
{
    $failed = [
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode($payload),
        'exception' => 'RuntimeException: boom',
    ];

    return Blade::render(
        '<x-queue-insights::failed-modal :failed="$failed" :canRetry="false" expandedBatchId="" />',
        ['failed' => $failed],
    );
}

beforeEach(function (): void {
    config()->set('queue-insights.sentry.organization', 'acme');
});

it('renders the View in Sentry button + markdown link when configured and trace present', function (): void {
    $html = renderSentryFailedModal([
        'displayName' => 'App\\Jobs\\X',
        'attempts' => 1,
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($html)
        ->toContain('View in Sentry')
        // assert the rendered anchor href itself, so a sink regression is noisy
        ->toContain('href="https://acme.sentry.io/issues/?query=trace:494026a4a8ee43ebaeff095dc2772f54"')
        // markdown export
        ->toContain('- **Sentry:** https://acme.sentry.io/issues/?query=trace:494026a4a8ee43ebaeff095dc2772f54');
});

it('omits the Sentry button when no org slug is configured', function (): void {
    config()->set('queue-insights.sentry.organization');

    $html = renderSentryFailedModal([
        'displayName' => 'App\\Jobs\\X',
        'attempts' => 1,
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($html)
        ->not->toContain('View in Sentry')
        ->not->toContain('- **Sentry:**');
});

it('omits the Sentry button when the payload carries no trace data', function (): void {
    $html = renderSentryFailedModal([
        'displayName' => 'App\\Jobs\\X',
        'attempts' => 1,
    ]);

    expect($html)
        ->not->toContain('View in Sentry')
        ->not->toContain('- **Sentry:**');
});
