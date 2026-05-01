<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use BackedEnum;

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

    /**
     * Read a backed string-enum from config. Hosts keep writing the
     * raw string (`'metadata'`, `'critical'`, ...); the package converts
     * to the typed case at the call site so internal code is exhaustive
     * over the cases.
     *
     * Falls back to `$default` when the config key is missing or holds
     * a value that doesn't map to any case — same conservative shape
     * the per-detector `severity()` helpers used to have.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     * @return T
     */
    public static function enum(string $key, string $enum, BackedEnum $default): BackedEnum
    {
        $value = config('queue-insights.' . $key);

        if ($value instanceof $enum) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return $enum::tryFrom($value) ?? $default;
        }

        return $default;
    }
}
