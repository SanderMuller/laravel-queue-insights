<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Carbon\CarbonImmutable;

/**
 * Helpers for the hourly bucket key shape (`YmdH`) used by every listener
 * + detector that writes per-class counters.
 */
final class HourBucket
{
    /**
     * Resolve the unix-timestamp start-of-hour for a `YmdH` bucket label.
     * Falls back to the current hour's start if the label fails to parse —
     * the caller is computing an EXPIREAT, so any reasonable timestamp is
     * better than aborting the listener.
     */
    public static function startTs(string $bucket): int
    {
        $dt = CarbonImmutable::createFromFormat('YmdH', $bucket, 'UTC');

        if (! $dt instanceof CarbonImmutable) {
            return CarbonImmutable::now('UTC')->startOfHour()->getTimestamp();
        }

        return $dt->startOfHour()->getTimestamp();
    }
}
