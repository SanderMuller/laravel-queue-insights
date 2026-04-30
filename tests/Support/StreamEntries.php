<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

/**
 * Type-narrows the `mixed` return from `R::raw('xrange', ...)` into a clean
 * `[id => [field => value]]` map so PHPStan-max can see what tests are
 * iterating over.
 */
final class StreamEntries
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function fromXrange(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $id => $fields) {
            if (! is_string($id)) {
                continue;
            }

            if (! is_array($fields)) {
                continue;
            }

            $clean = [];
            foreach ($fields as $field => $value) {
                if (! is_string($field)) {
                    continue;
                }

                if (is_string($value)) {
                    $clean[$field] = $value;
                } elseif (is_int($value) || is_float($value)) {
                    $clean[$field] = (string) $value;
                }
            }

            $out[$id] = $clean;
        }

        return $out;
    }

    /**
     * Group stream-entry rows by the `class` field for ergonomic per-class
     * lookup in tests. Rows without a `class` field are silently dropped.
     *
     * @param  array<string, array<string, string>>  $entries
     * @return array<string, array<string, string>>
     */
    public static function byClass(array $entries): array
    {
        $out = [];
        foreach ($entries as $fields) {
            $class = $fields['class'] ?? null;
            if (is_string($class) && $class !== '') {
                $out[$class] = $fields;
            }
        }

        return $out;
    }
}
