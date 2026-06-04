<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Validates the `failure_context` config block — Context + environment capture
 * on job/task failure. Split out of `ConfigValidator` to keep that class under
 * the cognitive-complexity cap (same pattern as `AlertsConfigValidator`).
 * Missing-key tolerant (shallow-merge friendly).
 *
 * @internal
 */
final class FailureContextConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $failureContext
     */
    public static function validate(array $failureContext): void
    {
        self::validateBools($failureContext);
        self::validatePositiveInts($failureContext);
        self::validateContextKeys($failureContext);
        self::validateReleaseResolver($failureContext);
    }

    /**
     * @param  array<array-key, mixed>  $failureContext
     */
    private static function validateBools(array $failureContext): void
    {
        foreach (['enabled', 'capture_app_context', 'capture_environment'] as $key) {
            if (isset($failureContext[$key]) && ! is_bool($failureContext[$key])) {
                throw new QueueInsightsConfigException(
                    "queue-insights.failure_context.{$key} must be a boolean."
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $failureContext
     */
    private static function validatePositiveInts(array $failureContext): void
    {
        foreach (['max_value_bytes', 'ttl_seconds'] as $key) {
            if (! isset($failureContext[$key])) {
                continue;
            }

            $value = $failureContext[$key];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    "queue-insights.failure_context.{$key} must be a positive integer."
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $failureContext
     */
    private static function validateContextKeys(array $failureContext): void
    {
        if (! isset($failureContext['context_keys'])) {
            return;
        }

        $keys = $failureContext['context_keys'];
        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new QueueInsightsConfigException(
                'queue-insights.failure_context.context_keys must be a list of strings.'
            );
        }

        foreach ($keys as $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new QueueInsightsConfigException(
                    'queue-insights.failure_context.context_keys entries must be non-empty strings.'
                );
            }
        }
    }

    /**
     * release_resolver: null | string (config key) | callable. A closure can't
     * appear in published config (only hand-wired), so accept those shapes and
     * reject other scalars.
     *
     * @param  array<array-key, mixed>  $failureContext
     */
    private static function validateReleaseResolver(array $failureContext): void
    {
        if (! isset($failureContext['release_resolver'])) {
            return;
        }

        $resolver = $failureContext['release_resolver'];
        if (! is_string($resolver) && ! is_callable($resolver)) {
            throw new QueueInsightsConfigException(
                'queue-insights.failure_context.release_resolver must be null, a config-key string, or a callable.'
            );
        }
    }
}
