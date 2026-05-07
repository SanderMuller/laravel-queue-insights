<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Builds the `LOWER(payload) LIKE ? ESCAPE '|'` needle/escape pair used to
 * match a class FQCN against the JSON `displayName` field on the
 * `failed_jobs.payload` column. Shared between the include filter
 * (`QueueInsights::applyFailedJobFilters`) and the silenced exclusion
 * (`SilencedJobs::appendExclusion`) so a future fix to the JSON-escape /
 * wildcard-escape rules lands in one place.
 *
 * The class FQCN sits in the JSON column as `"displayName":"App\\Jobs\\Foo"`
 * — `\` JSON-escaped to `\\`. We match that byte sequence by re-encoding
 * the FQCN through `json_encode` (same `\\` form) and stripping the outer
 * quotes.
 *
 * `ESCAPE '|'` makes the LIKE engine treat `|` as the escape char instead
 * of the default `\`. Without it, MySQL's default backslash-as-escape rule
 * consumes the literal `\\` in the pattern back to a single `\`, which
 * never matches the JSON column's `\\`. PostgreSQL/SQLite are unaffected
 * but `ESCAPE '|'` is portable across all three.
 *
 * `LOWER()` on both sides handles deep-linked filters with mismatched
 * casing without depending on DB-specific LIKE collation.
 */
final class DisplayNamePayloadMatch
{
    public const string ESCAPE = '|';

    /**
     * @return array{0: string, 1: string}|null  [pattern, escape] tuple, or
     *         null when the class can't be JSON-encoded.
     */
    public static function pattern(string $class): ?array
    {
        $encoded = json_encode($class, JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return null;
        }

        $needle = strtolower(trim($encoded, '"'));
        // Escape LIKE wildcards (and the ESCAPE char itself) so a class
        // name containing `%` / `_` / `|` can't smuggle a wildcard match.
        $needle = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $needle);

        return ['%"displayname":"' . $needle . '%', self::ESCAPE];
    }

    /**
     * Build a LIKE pattern from a `Str::is`-style glob — the literal `*`
     * segments translate to SQL `%` wildcards while every other character
     * (including `_` / `%` / `|`) is escaped exactly like `pattern()`.
     * `?` is treated as a literal — `Str::is` does not support `?`, so we
     * keep the SQL pattern in lockstep with the in-PHP match.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function patternFromGlob(string $glob): ?array
    {
        $segments = explode('*', $glob);
        $encoded = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                $encoded[] = '';

                continue;
            }

            $jsonEncoded = json_encode($segment, JSON_UNESCAPED_UNICODE);
            if (! is_string($jsonEncoded)) {
                return null;
            }

            $needle = strtolower(trim($jsonEncoded, '"'));
            $needle = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $needle);
            $encoded[] = $needle;
        }

        return ['%"displayname":"' . implode('%', $encoded) . '%', self::ESCAPE];
    }
}
