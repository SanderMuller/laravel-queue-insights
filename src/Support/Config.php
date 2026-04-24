<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Typed accessors for `config('queue-insights.*')`. Replaces scattered
 * `(string) config(...)` casts that PHPStan can't narrow from `mixed`.
 */
final class Config
{
    public static function string(string $key, string $default = ''): string
    {
        $value = config('queue-insights.' . $key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = config('queue-insights.' . $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = config('queue-insights.' . $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));

            return match ($lower) {
                'true', 'yes', 'on' => true,
                'false', 'no', 'off', '' => false,
                default => $default,
            };
        }

        return $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(string $key): array
    {
        $value = config('queue-insights.' . $key, []);

        return is_array($value) ? $value : [];
    }
}
