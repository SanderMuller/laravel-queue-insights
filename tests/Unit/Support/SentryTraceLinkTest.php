<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\SentryTraceLink;

/**
 * Builds the failed-job → Sentry issue deep-link from the sentry-laravel
 * tracing data in the queue payload. The trace id is the only stable handle:
 * it links the issue stream (which resolves even for unsampled traces, since
 * error capture is independent of trace sampling), not the performance view.
 */
beforeEach(function (): void {
    config()->set('queue-insights.sentry.organization', 'acme');
    config()->set(
        'queue-insights.sentry.issue_url_template',
        'https://{org}.sentry.io/issues/?query=trace:{trace}',
    );
});

it('builds the issue link from sentry_trace_parent_data', function (): void {
    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($url)->toBe('https://acme.sentry.io/issues/?query=trace:494026a4a8ee43ebaeff095dc2772f54');
});

it('falls back to the trace id inside sentry_baggage_data', function (): void {
    $url = SentryTraceLink::for([
        'sentry_baggage_data' => 'sentry-environment=production,sentry-trace_id=494026a4a8ee43ebaeff095dc2772f54,sentry-sampled=false',
    ]);

    expect($url)->toBe('https://acme.sentry.io/issues/?query=trace:494026a4a8ee43ebaeff095dc2772f54');
});

it('lowercases an uppercase trace id', function (): void {
    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026A4A8EE43EBAEFF095DC2772F54-F1B7542623584B99',
    ]);

    expect($url)->toBe('https://acme.sentry.io/issues/?query=trace:494026a4a8ee43ebaeff095dc2772f54');
});

it('returns null when no org slug is configured', function (): void {
    config()->set('queue-insights.sentry.organization', null);

    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($url)->toBeNull();
});

it('returns null when the payload carries no trace data', function (): void {
    expect(SentryTraceLink::for(['displayName' => 'App\\Jobs\\X']))->toBeNull();
    expect(SentryTraceLink::for(null))->toBeNull();
});

it('returns null when the trace id is malformed', function (): void {
    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => 'not-a-valid-trace',
    ]);

    expect($url)->toBeNull();
});

it('returns null for an unsafe org slug so the href cannot break out', function (): void {
    config()->set('queue-insights.sentry.organization', 'acme/../evil');

    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($url)->toBeNull();
});

it('returns null when the template scheme is non-https, even if mutated after boot', function (): void {
    config()->set('queue-insights.sentry.issue_url_template', 'javascript:alert(1){trace}');

    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($url)->toBeNull();
});

it('rejects an over-length baggage trace id rather than truncating it', function (): void {
    $url = SentryTraceLink::for([
        // 33 hex chars — must not silently extract the first 32.
        'sentry_baggage_data' => 'sentry-trace_id=494026a4a8ee43ebaeff095dc2772f544,sentry-environment=production',
    ]);

    expect($url)->toBeNull();
});

it('honours a custom url template', function (): void {
    config()->set(
        'queue-insights.sentry.issue_url_template',
        'https://sentry.example.com/organizations/{org}/issues/?query=trace%3A{trace}',
    );

    $url = SentryTraceLink::for([
        'sentry_trace_parent_data' => '494026a4a8ee43ebaeff095dc2772f54-f1b7542623584b99',
    ]);

    expect($url)->toBe('https://sentry.example.com/organizations/acme/issues/?query=trace%3A494026a4a8ee43ebaeff095dc2772f54');
});
