<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

/**
 * Shared field-projection helpers for the `hgetall`-shaped Redis hashes
 * the scheduler subsystem reads. Centralised so RunsQuery /
 * AggregatesQuery / ScheduleReader can't drift on field-coercion rules.
 */
final class HashFields
{
    /**
     * @param  array<array-key, mixed>  $hash
     */
    public static function int(array $hash, string $field): int
    {
        $value = $hash[$field] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $hash
     */
    public static function nullableInt(array $hash, string $field): ?int
    {
        $value = $hash[$field] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Coerce a `'1'` / `'0'` / numeric flag stored on a Redis hash to a
     * native bool. Mirrors how the listeners write the flag — anything
     * other than literal `'1'` (or numeric 1) reads as false.
     *
     * @param  array<array-key, mixed>  $hash
     */
    public static function bool01(array $hash, string $field): bool
    {
        $value = $hash[$field] ?? null;

        return (is_string($value) || is_numeric($value)) && (string) $value === '1';
    }

    /**
     * @param  array<array-key, mixed>  $hash
     */
    public static function string(array $hash, string $field, string $default): string
    {
        $value = $hash[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return ?array<array-key, mixed>
     */
    public static function decodeJson(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
