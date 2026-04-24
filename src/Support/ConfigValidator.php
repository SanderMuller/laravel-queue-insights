<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;
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
}
