<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Builds a Sentry deep-link for a failed job from the distributed-tracing data
 * that sentry-laravel injects into the queue payload
 * (`sentry_trace_parent_data` / `sentry_baggage_data`).
 *
 * Links to the ISSUE stream filtered by `trace:{id}` rather than the
 * performance/trace view: the failure error event is captured under Sentry's
 * error `sample_rate`, which is independent of `traces_sample_rate`. So the
 * issue link resolves even when the trace itself was sampled out
 * (`sentry-sampled=false` in the baggage) — whereas the performance trace view
 * would 404. The payload carries no `event_id` (that is generated at capture
 * time, not at dispatch), so the trace id is the only stable handle available.
 *
 * Returns null unless an org slug is configured AND a 32-hex trace id is found,
 * so the button self-hides for hosts without sentry-laravel.
 *
 * Effectively internal (only the failed-job modal calls it), but kept
 * un-`@internal` so the unit-test suite (separate root namespace) can call it
 * without tripping the static-analysis internal-class rule.
 */
final class SentryTraceLink
{
    private const string DEFAULT_TEMPLATE = 'https://{org}.sentry.io/issues/?query=trace:{trace}';

    /**
     * @param  array<array-key, mixed>|null  $payload  Decoded failed_jobs payload.
     */
    public static function for(?array $payload): ?string
    {
        // Slug charset guard — the org is interpolated into the link domain
        // (`{org}.sentry.io`), so a slash- or scheme-bearing value could break
        // the host out to an arbitrary URL. Boot validation rejects this too;
        // re-checking here keeps the builder self-safe (empty = disabled).
        $org = Config::string('sentry.organization');
        if ($org === '' || preg_match('/^[A-Za-z0-9_-]+$/', $org) !== 1) {
            return null;
        }

        $trace = self::traceId($payload);
        if ($trace === null) {
            return null;
        }

        // Scheme guard at the sink — boot validation already enforces https://,
        // but the template is read fresh on every render, so a post-boot
        // config mutation must not be able to reintroduce a javascript: (or
        // other non-https) scheme into the rendered href.
        $template = Config::string('sentry.issue_url_template', self::DEFAULT_TEMPLATE);
        if (! str_starts_with($template, 'https://')) {
            return null;
        }

        return strtr($template, ['{org}' => $org, '{trace}' => $trace]);
    }

    /**
     * For scheduled-task failures, where no distributed-trace ID is available
     * in the payload (unlike queue jobs). The event UUID is recognised by
     * Sentry's issue-stream search without a prefix.
     *
     * Derives the base URL from `issue_url_template` (strips the query) so
     * self-hosted Sentry deployments that already customised the template get
     * the correct host without a separate config key.
     */
    public static function forEventId(string $eventId): ?string
    {
        $org = Config::string('sentry.organization');
        if ($org === '' || preg_match('/^[A-Za-z0-9_-]+$/', $org) !== 1) {
            return null;
        }

        // Accept 32-hex with or without hyphens.
        $normalized = str_replace('-', '', $eventId);
        if (preg_match('/^[0-9a-f]{32}$/i', $normalized) !== 1) {
            return null;
        }

        // Reuse the issue_url_template base URL (before the `?`) so a custom
        // template for self-hosted Sentry automatically applies here too.
        $template = Config::string('sentry.issue_url_template', self::DEFAULT_TEMPLATE);
        if (! str_starts_with($template, 'https://')) {
            return null;
        }

        $withOrg = str_replace('{org}', $org, $template);
        $baseUrl = str_contains($withOrg, '?')
            ? (string) strstr($withOrg, '?', true)
            : $withOrg;

        // Strip any {trace} placeholder that survives in the path (e.g. a
        // template like `https://sentry.example.com/issues/{trace}` has no
        // query string, so strstr above leaves the literal in $baseUrl).
        $baseUrl = str_replace('{trace}', '', $baseUrl);

        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl . '?query=' . strtolower($normalized);
    }

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    private static function traceId(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        // Preferred: `sentry_trace_parent_data` = "{trace_id}-{span_id}[-{sampled}]".
        $parent = $payload['sentry_trace_parent_data'] ?? null;
        if (is_string($parent) && $parent !== '') {
            $head = strstr($parent, '-', true);
            $candidate = $head === false ? $parent : $head;
            if (preg_match('/^[0-9a-f]{32}$/i', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        // Fallback: `sentry-trace_id=...` inside the W3C baggage string. The
        // trailing boundary rejects an over-length id (33+ hex) rather than
        // silently truncating it to the first 32.
        $baggage = $payload['sentry_baggage_data'] ?? null;
        if (is_string($baggage) && preg_match('/sentry-trace_id=([0-9a-f]{32})(?![0-9a-f])/i', $baggage, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
