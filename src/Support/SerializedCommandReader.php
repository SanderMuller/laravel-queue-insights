<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Throwable;

/**
 * Reads PHP-serialized job-command payloads (the `data.command` field on a queue
 * payload) without instantiating the underlying class. Uses `allowed_classes => false`
 * so __wakeup, __destruct and constructors never run — safe to call on stored data
 * without re-triggering app side-effects.
 *
 * Property keys with the null-byte prefix encoding for protected (`\0*\0name`) and
 * private (`\0ClassName\0name`) members are cleaned to the bare name.
 */
final class SerializedCommandReader
{
    /**
     * @return array{class: string|null, properties: array<string, mixed>}|null
     *   `null` if the blob does not unserialize to an object.
     */
    public static function extract(string $blob): ?array
    {
        try {
            // `@` suppresses the E_WARNING that allowed_classes=false emits when it
            // demotes user-defined classes to __PHP_Incomplete_Class — we expect that.
            $decoded = @unserialize($blob, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        if (! is_object($decoded)) {
            return null;
        }

        $arr = (array) $decoded;
        $class = null;
        $properties = [];

        foreach ($arr as $key => $value) {
            $strKey = (string) $key;
            if ($strKey === '__PHP_Incomplete_Class_Name') {
                $class = is_string($value) ? $value : null;

                continue;
            }

            $properties[self::cleanKey($strKey)] = $value;
        }

        return ['class' => $class, 'properties' => $properties];
    }

    /**
     * Expand a nested `__PHP_Incomplete_Class` (the kind produced by
     * `unserialize(... ['allowed_classes' => false])`) into a clean property map —
     * same shape as the `properties` field returned by `extract()`. Use this from
     * the modal renderer to recursively reveal nested objects without ever
     * instantiating their classes.
     *
     * @return array<string, mixed>
     */
    public static function expandObject(object $obj): array
    {
        $arr = (array) $obj;
        $properties = [];

        foreach ($arr as $key => $value) {
            $strKey = (string) $key;
            if ($strKey === '__PHP_Incomplete_Class_Name') {
                continue;
            }

            $properties[self::cleanKey($strKey)] = $value;
        }

        return $properties;
    }

    /**
     * Best-effort class-name extraction from a nested `__PHP_Incomplete_Class` instance.
     */
    public static function classNameOf(object $obj): ?string
    {
        $arr = (array) $obj;
        $name = $arr['__PHP_Incomplete_Class_Name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * `\0*\0name` → `name` (protected); `\0ClassName\0name` → `name` (private).
     * Public properties have no null-byte prefix, returned unchanged.
     */
    private static function cleanKey(string $key): string
    {
        if (! str_contains($key, "\0")) {
            return $key;
        }

        $parts = explode("\0", $key);

        return $parts[count($parts) - 1];
    }

    /**
     * Best-effort one-line summary for a value extracted from a serialized job.
     * Recurses one level into __PHP_Incomplete_Class instances ("ClassName {…}").
     */
    public static function summarize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_object($value)) {
            $arr = (array) $value;
            $class = $arr['__PHP_Incomplete_Class_Name'] ?? $value::class;

            return is_string($class) ? $class . ' {…}' : 'object {…}';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : 'array';
        }

        return gettype($value);
    }
}
