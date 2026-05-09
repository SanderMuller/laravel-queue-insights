<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

/**
 * Driver-tolerant `mixed → list<string>` coercion for Redis results
 * that should be a list of non-empty string entries.
 *
 * Used by `ClassFilter` and `TaskFilter` to normalise the result of
 * `ZRANGE` / `LRANGE` calls — phpredis returns a positional `array`,
 * Predis can return associative-by-key under some configurations, and
 * either driver may surface an unexpected non-array value (`false` /
 * `null`) on a failed command. The same shape would otherwise be
 * inlined into every roster filter.
 *
 * @internal
 */
final class StringList
{
    /**
     * @return list<string>
     */
    public static function coerce(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
