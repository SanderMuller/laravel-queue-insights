<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Pure key + fingerprint builder for the backward-chain claim ticket.
 *
 * The claim key is a deterministic function of (connection, queue, nextClass,
 * tail-class fingerprint). Two parents with the same chain shape — same next
 * class and same remaining-tail classes — collide on this key by design;
 * the FIFO list semantics in `ChainLineageStore` bound that collision to
 * "attribution in dispatch order" rather than "last writer wins".
 *
 * No I/O — pure functions only, kept separate from the store so the key
 * derivation is unit-testable without a Redis fixture.
 */
final class ChainLineageClaim
{
    /**
     * Build the claim-list key for a specific (connection, queue, next-class,
     * tail-shape) tuple. The returned string is already prefixed with the
     * package's key prefix and is safe to pass straight to Redis primitives.
     *
     * @param  list<string>  $tailClasses  Classes of every link AFTER `nextClass`
     *                                     in the parent's `chained` array. Empty when
     *                                     `nextClass` is the last link.
     */
    public static function key(string $connection, string $queue, string $nextClass, array $tailClasses): string
    {
        $fingerprint = self::fingerprint($tailClasses);

        return KeyPrefix::make("chain-claim:{$connection}:{$queue}:{$nextClass}:{$fingerprint}");
    }

    /**
     * Stable hash of the JSON-encoded class list. xxh3 is non-cryptographic
     * (matches the use case — collision resistance for cache keys, not
     * security) and 16-char so the key stays compact. Empty list still
     * produces a deterministic hash so the key shape is uniform.
     *
     * @param  list<string>  $tailClasses
     */
    public static function fingerprint(array $tailClasses): string
    {
        $encoded = json_encode($tailClasses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('xxh3', $encoded === false ? '[]' : $encoded);
    }
}
