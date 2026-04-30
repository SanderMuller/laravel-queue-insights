<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

final class ConfigValidator
{
    /**
     * Validate the snapshots list, asserting that no two entries on the same
     * connection normalize to the same canonical queue key.
     *
     * @param  array<array-key, mixed>  $snapshots
     */
    public static function validateSnapshots(array $snapshots): void
    {
        $seen = [];

        foreach ($snapshots as $index => $entry) {
            if (! is_array($entry) || ! isset($entry['connection'], $entry['queue'])) {
                throw new QueueInsightsConfigException(
                    "queue-insights.snapshots[{$index}] must be ['connection' => ..., 'queue' => ...]."
                );
            }

            $connection = $entry['connection'];
            $queue = $entry['queue'];

            if (! is_string($connection) || ! is_string($queue)) {
                throw new QueueInsightsConfigException(
                    "queue-insights.snapshots[{$index}] connection and queue must both be strings."
                );
            }

            if ($connection === '') {
                throw new QueueInsightsConfigException(
                    "queue-insights.snapshots[{$index}] has an empty connection."
                );
            }

            try {
                $canonical = CanonicalQueueKey::from($queue);
            } catch (InvalidArgumentException $e) {
                throw new QueueInsightsConfigException("queue-insights.snapshots[{$index}] invalid queue: " . $e->getMessage(), $e->getCode(), previous: $e);
            }

            $slot = $connection . '|' . $canonical;

            if (isset($seen[$slot])) {
                throw new QueueInsightsConfigException(sprintf(
                    'queue-insights.snapshots collision on connection [%s]: entries [%s] and [%s] both normalize to canonical key [%s].',
                    $connection,
                    $seen[$slot],
                    $queue,
                    $canonical,
                ));
            }

            $seen[$slot] = $queue;
        }
    }

    /**
     * Validate the pending-tracking block. Type-checks the four user-tunable
     * keys; missing keys take their defaults from config/queue-insights.php.
     *
     * @param  array<array-key, mixed>  $pending
     */
    public static function validatePending(array $pending): void
    {
        if (isset($pending['enabled']) && ! is_bool($pending['enabled'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.pending.enabled must be a boolean.'
            );
        }

        foreach (['max_per_queue', 'ttl_seconds', 'gap_warn_threshold'] as $key) {
            if (! isset($pending[$key])) {
                continue;
            }

            $value = $pending[$key];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    "queue-insights.pending.{$key} must be a positive integer."
                );
            }
        }
    }

    /**
     * Validate the batches-tracking block. Mirrors `validatePending`.
     *
     * @param  array<array-key, mixed>  $batches
     */
    public static function validateBatches(array $batches): void
    {
        if (isset($batches['enabled']) && ! is_bool($batches['enabled'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.batches.enabled must be a boolean.'
            );
        }

        foreach (['max_uuids_per_batch', 'max_per_query', 'ttl_seconds'] as $key) {
            if (! isset($batches[$key])) {
                continue;
            }

            $value = $batches[$key];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    "queue-insights.batches.{$key} must be a positive integer."
                );
            }
        }
    }

    /**
     * Validate the alerts block. Type-checks the cooldown, every per-rule
     * shape, every threshold entry, and channel feature toggles. Delegates
     * to `AlertsConfigValidator` so this class stays under PHPStan's
     * cognitive-complexity ceiling.
     *
     * @param  array<array-key, mixed>  $alerts
     */
    public static function validateAlerts(array $alerts): void
    {
        AlertsConfigValidator::validate($alerts);
    }

    /**
     * Validate the chain_lineage block. Type-checks the toggle, the redis
     * connection override (when set), and the two TTLs.
     *
     * @param  array<array-key, mixed>  $chainLineage
     */
    /**
     * Type-check `capture.payloads` so a typo at boot ("metadta") fails
     * with a clear error rather than silently degrading to the default.
     *
     * @param  array<array-key, mixed>  $capture
     */
    public static function validateCapture(array $capture): void
    {
        if (! array_key_exists('payloads', $capture)) {
            return;
        }

        $value = $capture['payloads'];
        if (! is_string($value) || CaptureMode::tryFrom($value) === null) {
            throw new QueueInsightsConfigException(
                'queue-insights.capture.payloads must be one of: off, metadata, full.'
            );
        }
    }

    /**
     * Validate the work block. Type-checks `shutdown_grace_seconds` as a
     * positive integer. Hard exception on misconfiguration matches the
     * rest of the validator surface — silent default-fallback would mask
     * a typo that ultimately decides whether an unresponsive child gets
     * SIGKILL'd at all.
     *
     * @param  array<array-key, mixed>  $work
     */
    public static function validateWork(array $work): void
    {
        if (! array_key_exists('shutdown_grace_seconds', $work)) {
            return;
        }

        $value = $work['shutdown_grace_seconds'];
        if (! is_int($value) || $value < 1) {
            throw new QueueInsightsConfigException(
                'queue-insights.work.shutdown_grace_seconds must be a positive integer.'
            );
        }
    }

    /**
     * Validate the chain_lineage block. Type-checks the toggle, the redis
     * connection override (when set), and the two TTLs.
     *
     * @param  array<array-key, mixed>  $chainLineage
     */
    public static function validateChainLineage(array $chainLineage): void
    {
        if (isset($chainLineage['enabled']) && ! is_bool($chainLineage['enabled'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.chain_lineage.enabled must be a boolean.'
            );
        }

        // `isset()` returns false when the value is null, so this branch only
        // fires for non-null overrides — the default-null path needs no check.
        if (isset($chainLineage['redis_connection'])) {
            $connection = $chainLineage['redis_connection'];
            if (! is_string($connection) || $connection === '') {
                throw new QueueInsightsConfigException(
                    'queue-insights.chain_lineage.redis_connection must be a non-empty string or null.'
                );
            }
        }

        foreach (['claim_ttl_seconds', 'lineage_ttl_seconds'] as $key) {
            if (! isset($chainLineage[$key])) {
                continue;
            }

            $value = $chainLineage[$key];
            if (! is_int($value) || $value < 1) {
                throw new QueueInsightsConfigException(
                    "queue-insights.chain_lineage.{$key} must be a positive integer."
                );
            }
        }
    }
}
