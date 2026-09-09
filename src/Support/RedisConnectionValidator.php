<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Checks that a configured Redis connection name resolves to something.
 *
 * A name that resolves to nothing fails later, deep inside a listener or the
 * sweeper, as whatever the Redis manager throws when it cannot build the
 * connection — with no mention of which name was asked for. Failing at boot
 * names it.
 */
final class RedisConnectionValidator
{
    /**
     * @param  string  $key  config key being validated, for the message
     */
    public static function validate(string $connection, string $key): void
    {
        if ($connection === '') {
            throw new QueueInsightsConfigException(
                "queue-insights.{$key} must be a non-empty Redis connection name."
            );
        }

        $connections = config('database.redis.connections', []);
        $clusters = config('database.redis.clusters', []);

        $known = array_merge(
            is_array($connections) ? array_keys($connections) : [],
            is_array($clusters) ? array_keys($clusters) : [],
        );

        // An empty redis config means the host has not configured Redis at
        // all — a different failure, reported by Laravel itself, and not
        // something to pre-empt with a misleading "unknown connection".
        if ($known === []) {
            return;
        }

        if (! in_array($connection, $known, true)) {
            throw new QueueInsightsConfigException(sprintf(
                'queue-insights.%s names the Redis connection "%s", which is not defined under database.redis.connections. Known: %s.',
                $key,
                $connection,
                implode(', ', array_map(strval(...), $known)),
            ));
        }
    }
}
