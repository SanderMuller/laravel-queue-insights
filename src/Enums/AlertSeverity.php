<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Enums;

/**
 * Severity tier on every fired `Issue` and on each per-rule
 * `alerts.rules.*.severity` config slot.
 *
 * Backed by the historical string config so hosts keep writing
 * `'severity' => 'critical'`. Internal callers use the enum (detectors,
 * `Issue` value object, dispatcher cooldown keys), and host-facing
 * `Events\*` keep `public string $severity` so the event surface stays
 * string-typed for backwards compatibility.
 */
enum AlertSeverity: string
{
    case Warning = 'warning';

    case Critical = 'critical';

    /**
     * Coerce a raw config string into the enum. Returns `Warning`
     * (the conservative default) when the value is missing or unknown,
     * matching the `in_array(...) ? $v : 'warning'` pattern the
     * detectors had before this migration.
     */
    public static function fromConfig(mixed $value, self $default = self::Warning): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return self::tryFrom($value) ?? $default;
        }

        return $default;
    }

    /**
     * Higher = more urgent. Used by `DepthDetector` to pick the
     * highest-severity matching threshold per tick.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
