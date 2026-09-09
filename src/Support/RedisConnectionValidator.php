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
    /** Keys under `database.redis` that are not connection names. */
    private const array RESERVED = ['client', 'options', 'clusters'];

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

        $known = self::knownNames();

        // No Redis configured at all — a different failure, reported by
        // Laravel itself, and not one to pre-empt with a misleading
        // "unknown connection". Testbench boots in exactly this shape.
        if ($known === []) {
            return;
        }

        if (! in_array($connection, $known, true)) {
            throw new QueueInsightsConfigException(sprintf(
                'queue-insights.%s names the Redis connection "%s", which is not defined under database.redis. Known: %s.',
                $key,
                $connection,
                implode(', ', $known),
            ));
        }
    }

    /**
     * Connection names as Laravel resolves them: every key under
     * `database.redis` bar the reserved ones, plus every key under
     * `database.redis.clusters` bar its own `options`.
     *
     * @return list<string>
     */
    private static function knownNames(): array
    {
        $redis = config('database.redis', []);
        $clusters = config('database.redis.clusters', []);

        $names = is_array($redis)
            ? array_diff(array_keys($redis), self::RESERVED)
            : [];

        $clusterNames = is_array($clusters)
            ? array_diff(array_keys($clusters), ['options'])
            : [];

        return array_values(array_map(
            strval(...),
            array_merge($names, $clusterNames),
        ));
    }
}
