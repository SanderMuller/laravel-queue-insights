<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Validates the `sentry` config block — the failed-job → Sentry deep-link.
 * Split out of `ConfigValidator` to keep that class under the
 * cognitive-complexity cap (same pattern as `FailureContextConfigValidator`).
 * Missing-key tolerant (shallow-merge friendly).
 *
 * `organization` is the org slug (null/absent disables the button);
 * `issue_url_template` is the URL pattern with `{org}`/`{trace}` placeholders.
 *
 * @internal
 */
final class SentryConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $sentry
     */
    public static function validate(array $sentry): void
    {
        self::validateOrganization($sentry);
        self::validateTemplate($sentry);
    }

    /**
     * The org slug is interpolated into the link URL (`{org}.sentry.io`), so it
     * must be a bare slug — letters, digits, hyphens, underscores. A slash or
     * scheme would let the rendered `href` break out to an arbitrary URL. An
     * empty string is allowed and disables the button.
     *
     * @param  array<array-key, mixed>  $sentry
     */
    private static function validateOrganization(array $sentry): void
    {
        if (! isset($sentry['organization'])) {
            return;
        }

        $org = $sentry['organization'];
        if (! is_string($org)) {
            throw new QueueInsightsConfigException(
                'queue-insights.sentry.organization must be a string or null.'
            );
        }

        if ($org !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $org) !== 1) {
            throw new QueueInsightsConfigException(
                'queue-insights.sentry.organization must be a Sentry org slug '
                . '(letters, digits, hyphens, underscores) — it is interpolated into the link URL.'
            );
        }
    }

    /**
     * The template is rendered verbatim into an anchor `href`, so it must be an
     * absolute `https://` URL (blocks `javascript:` and other schemes) and must
     * carry the `{trace}` placeholder or the link is not failure-specific.
     *
     * @param  array<array-key, mixed>  $sentry
     */
    private static function validateTemplate(array $sentry): void
    {
        if (! isset($sentry['issue_url_template'])) {
            return;
        }

        $template = $sentry['issue_url_template'];
        if (! is_string($template) || $template === '') {
            throw new QueueInsightsConfigException(
                'queue-insights.sentry.issue_url_template must be a non-empty string.'
            );
        }

        if (! str_starts_with($template, 'https://')) {
            throw new QueueInsightsConfigException(
                'queue-insights.sentry.issue_url_template must be an absolute https:// URL.'
            );
        }

        if (! str_contains($template, '{trace}')) {
            throw new QueueInsightsConfigException(
                'queue-insights.sentry.issue_url_template must contain the {trace} placeholder.'
            );
        }
    }
}
