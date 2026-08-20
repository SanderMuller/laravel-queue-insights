<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

/**
 * Decides whether the running console command warrants a snapshot
 * rebuild — see `RebuildScheduleSnapshot` for why the write is scoped
 * at all.
 *
 * Patterns come from `scheduler.snapshot_rebuild_commands`. An exact
 * command name matches literally; a trailing `*` matches by prefix.
 * An explicitly empty list disables the rebuild for every command; the
 * defaults apply only when the key is absent (host on a published
 * config predating it).
 */
final class SnapshotRebuildGate
{
    /** @var list<string> */
    private const array DEFAULT_PATTERNS = ['schedule:*', 'queue-insights:*'];

    public static function matches(?string $command): bool
    {
        if ($command === null || $command === '') {
            return false;
        }

        foreach (self::patterns() as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($command, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($command === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        // Raw config(), not Config::array() — that helper collapses a missing
        // key and an empty list to [], and the two mean opposite things here.
        $configured = config('queue-insights.scheduler.snapshot_rebuild_commands');
        if (! is_array($configured)) {
            return self::DEFAULT_PATTERNS;
        }

        $patterns = [];
        foreach ($configured as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }
}
