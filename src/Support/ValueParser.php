<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Detects payload values that are themselves a serialized/encoded blob and
 * returns the decoded structure so the dashboard can render it inline —
 * Sentry-style — instead of as one long opaque string.
 *
 * Two formats are recognised:
 *
 *  - **PHP-serialized** (`a:`, `O:`, `s:`, `i:`, `b:`, `d:`, `N;` openers).
 *    Common inside `data.command` and Laravel's `illuminate:log:context`
 *    entry — both surface to the dashboard as a string value the user has
 *    to scroll past. `unserialize` is invoked with `allowed_classes => false`
 *    so any object instance round-trips as `__PHP_Incomplete_Class` —
 *    constructors / `__wakeup` / `__destruct` never execute.
 *
 *  - **JSON containers** (`{…}` / `[…]`). Detected by a strict shape check
 *    so a plain string like `"hello"` is left alone.
 *
 * Returns `null` when the input is too short, not recognised, or decodes to
 * an empty container — letting callers fall through to their plain-string
 * renderer.
 *
 * Render-helper for the `nested-data` / `serialized-properties` blade
 * components. Not part of the package's semver-stable public API, but
 * kept un-`@internal` so the unit-test suite (which sits in a separate
 * root namespace) can exercise it directly.
 */
final class ValueParser
{
    /**
     * @return array<int|string, mixed>|null
     */
    public static function parse(string $value): ?array
    {
        // 6 chars is the shortest possible serialized container (`a:0:{}`)
        // or JSON object (`{"":0}` etc.). Anything shorter can't be valid.
        if (strlen($value) < 6) {
            return null;
        }

        if (self::looksLikePhpSerialized($value)) {
            $previous = error_reporting(0);
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            error_reporting($previous);

            if (is_array($decoded)) {
                return $decoded === [] ? null : $decoded;
            }

            if (is_object($decoded)) {
                $out = self::incompleteObjectToArray($decoded);

                return $out === [] ? null : $out;
            }
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '{' && $last === '}') || ($first === '[' && $last === ']')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        return null;
    }

    private static function looksLikePhpSerialized(string $value): bool
    {
        // Cheap prefix sniff — keeps the regex off most payload values.
        $opener = $value[0] ?? '';
        if (! in_array($opener, ['a', 'O', 's', 'i', 'b', 'd', 'N'], true)) {
            return false;
        }

        return preg_match(
            '/^(a:\d+:\{|O:\d+:"[^"]+":\d+:\{|s:\d+:"|i:-?\d+;|b:[01];|d:-?\d|N;)/',
            $value,
        ) === 1;
    }

    /**
     * Flatten an `__PHP_Incomplete_Class` back into an associative array,
     * stripping the null-byte scope markers PHP uses for protected/private
     * properties and surfacing the original class name under a `__class` key.
     *
     * @return array<string, mixed>
     */
    private static function incompleteObjectToArray(object $object): array
    {
        $out = [];
        foreach ((array) $object as $key => $value) {
            $key = (string) $key;
            $clean = preg_replace('/^\x00[^\x00]+\x00/', '', $key) ?? $key;
            if ($clean === '__PHP_Incomplete_Class_Name') {
                $out['__class'] = $value;

                continue;
            }
            $out[$clean] = $value;
        }

        return $out;
    }
}
