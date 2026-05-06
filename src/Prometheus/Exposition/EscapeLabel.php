<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Exposition;

/**
 * Prometheus exposition label-value escaping per the text format spec —
 * backslash, double-quote, and newline are the only required escapes.
 *
 * @internal
 */
final class EscapeLabel
{
    public static function value(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
        ]);
    }

    /**
     * Prometheus metric-name shape: `[a-zA-Z_:][a-zA-Z0-9_:]*`. Used by
     * the renderer to assert collector output before emitting a banner.
     */
    public static function isValidMetricName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name) === 1;
    }
}
