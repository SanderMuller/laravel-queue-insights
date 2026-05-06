<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Driver-agnostic decoder for the head of a `WITHSCORES`-flagged
 * `ZRANGE` / `ZRANGEBYSCORE` reply. phpredis and Predis both surface
 * the result as a `member => score` map through Laravel's redis
 * adapters, but `reset()` on the array picks the wrong end depending
 * on driver internals — iterate explicitly and type-check both halves.
 *
 * @internal
 */
final class ZsetHead
{
    /**
     * Returns `[member, score]` for the first valid pair in the reply,
     * or null when the reply is empty / malformed. Callers that need
     * an integer score should cast the float at the call site.
     *
     * @return array{0: string, 1: float}|null
     */
    public static function firstMemberScore(mixed $row): ?array
    {
        if (! is_array($row) || $row === []) {
            return null;
        }

        foreach ($row as $member => $score) {
            if (is_string($member) && $member !== '' && is_numeric($score)) {
                return [$member, (float) $score];
            }
        }

        return null;
    }
}
