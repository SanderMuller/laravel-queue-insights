<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

final class KeyPrefix
{
    /**
     * @return non-empty-string
     */
    public static function make(string $suffix): string
    {
        $prefix = Config::string('key_prefix', 'qm:');
        if ($prefix === '') {
            $prefix = 'qm:';
        }

        return $prefix . $suffix;
    }

    /**
     * Per-class key under the multi-connection-scoping dual-write shape.
     * Listeners write the (`{prefix}:{class}`) and (`{prefix}:{class}:{connection}`)
     * variants; readers select the variant by passing the connection or null.
     * Centralised here so writer and reader can't drift on key shape.
     *
     * Connection is canonicalised through `ConnectionAlias` so producer
     * (dispatcher) and worker connection names converge on the same key
     * when operators have declared `queue-insights.connection_aliases`.
     *
     * @return non-empty-string
     */
    public static function classKey(string $prefix, string $class, ?string $connection = null): string
    {
        if ($connection === null) {
            return self::make("{$prefix}:{$class}");
        }

        $canonical = ConnectionAlias::canonical($connection);

        return self::make("{$prefix}:{$class}:{$canonical}");
    }

    /**
     * Per-queue key. Canonicalises both connection (via `ConnectionAlias`)
     * and queue name (via `CanonicalQueueKey::fromOrDefault`) so producer +
     * worker + dashboard read paths converge on the same key segment.
     *
     * Use this for every `KeyPrefix::make("...:{$connection}:{$queue}")`
     * literal in listener / reader / snapshot paths.
     *
     * @return non-empty-string
     */
    public static function queueKey(string $prefix, string $connection, string $queue): string
    {
        $canonicalConn = ConnectionAlias::canonical($connection);
        $canonicalQueue = CanonicalQueueKey::fromOrDefault($queue, $canonicalConn);

        return self::make("{$prefix}:{$canonicalConn}:{$canonicalQueue}");
    }
}
