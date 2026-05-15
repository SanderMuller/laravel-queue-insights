<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

/**
 * String hygiene for any host-controlled value that ends up in an alert's
 * operator-facing surface (title, description, mail subject, Slack target,
 * log line). Strips Unicode `\p{C}` control characters, collapses
 * whitespace runs to a single space, trims, and caps at 200 chars so
 * pathological strings can't:
 *
 *   - blank the alert title by being whitespace-only,
 *   - split one alert into two log lines via embedded `\n`,
 *   - push past channel-side subject / title length limits.
 *
 * Originally scoped to scheduler task labels; promoted here so the
 * snapshot-error detector and any future host-string surfaces get the
 * same treatment without duplicating the regex tower.
 *
 * @internal
 */
final class AlertText
{
    public static function sanitise(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        // `\p{C}` matches all Unicode "other" (control) characters incl. CR/LF
        // and zero-width separators. `u` flag is mandatory for the class.
        $stripped = preg_replace('/\p{C}+/u', ' ', $value);
        if (! is_string($stripped)) {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $stripped);
        if (! is_string($collapsed)) {
            return '';
        }

        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > 200) {
            return mb_substr($trimmed, 0, 197) . '...';
        }

        return $trimmed;
    }
}
