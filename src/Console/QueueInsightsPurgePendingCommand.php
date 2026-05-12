<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * One-shot cleanup for `pending-zset:{connection}:{queue}` and its
 * companion `pending:{uuid}` hashes. Intended for hosts upgrading from
 * pre-0.16 builds where `RecordJobQueued` wrote pending entries under
 * the literal `'default'` queue when `$event->queue` was empty — once
 * 0.16's `CanonicalQueueKey::fromOrDefault` resolves the connection's
 * configured default, those orphan entries age out via the
 * `pending.ttl_seconds` TTL but operators who don't want to wait can
 * scrub them in one shot.
 *
 * Safety: every destructive call is gated on `--force`. Without it,
 * the command reports what it WOULD touch and exits without writing.
 * Per-uuid hashes are only deleted when their stored `queue` field
 * matches the target — bystander pending entries on the same Redis
 * instance are never touched.
 */
final class QueueInsightsPurgePendingCommand extends Command
{
    protected $signature = 'queue-insights:purge-pending
        {connection : Queue connection name (must be configured under `queue.connections.*`)}
        {queue : Queue value as recorded on the orphan zset (e.g. `default`)}
        {--force : Actually delete. Without this flag the command is a dry-run.}
        {--allow-live-queue : Override the live-default-queue refusal. Only set when you genuinely want to scrub the connection CURRENT default queue (not an orphan key).}';

    protected $description = 'Purge a pending-tracking zset + matching per-uuid hashes for a single (connection, queue) pair. Intended for pre-0.16 orphan cleanup ONLY — destroys every entry on the target zset.';

    public function handle(): int
    {
        $connection = (string) $this->argument('connection');
        $queueRaw = (string) $this->argument('queue');

        if ($queueRaw === '') {
            $this->error('queue argument is required and cannot be empty.');

            return self::INVALID;
        }

        if (config("queue.connections.{$connection}") === null) {
            $this->error("Queue connection [{$connection}] is not configured. Check config/queue.php.");

            return self::INVALID;
        }

        try {
            $canonical = CanonicalQueueKey::from($queueRaw);
        } catch (InvalidArgumentException $e) {
            $this->error("Invalid queue value [{$queueRaw}]: {$e->getMessage()}");

            return self::INVALID;
        }

        // Live-default refusal guard. The command's intended use is the
        // pre-0.16 orphan zset (under literal `'default'` when the
        // connection's real default is something else). If the operator
        // points it at the connection's CURRENT default queue, --force
        // would shred live in-flight pending tracking. Refuse unless the
        // override flag is set.
        $liveDefault = CanonicalQueueKey::fromOrDefault('', $connection);
        if ($canonical === $liveDefault && ! (bool) $this->option('allow-live-queue')) {
            $this->error(sprintf(
                'Refusing: %s IS the live default queue for connection [%s]. Producers are actively writing here; --force would delete in-flight pending visibility. Pass --allow-live-queue if you really want to scrub the live default queue.',
                $canonical,
                $connection,
            ));

            return self::INVALID;
        }
        $zsetKey = KeyPrefix::make("pending-zset:{$connection}:{$canonical}");
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $zcard = $redis->command('zcard', [$zsetKey]);
        $count = is_numeric($zcard) ? (int) $zcard : 0;

        if ($count === 0) {
            $this->info("No pending entries on {$zsetKey} — nothing to purge.");

            return self::SUCCESS;
        }

        // ZRANGE 0 4 returns at most 5 entries — no further slicing needed.
        $sample = $redis->command('zrange', [$zsetKey, 0, 4]);
        $uuids = is_array($sample) ? array_values(array_filter(
            $sample,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )) : [];

        $this->line("zset    : {$zsetKey}");
        $this->line("members : {$count}");
        if ($uuids !== []) {
            $this->line('sample  : ' . implode(', ', $uuids) . ($count > count($uuids) ? ', …' : ''));
        }

        if (! (bool) $this->option('force')) {
            $this->printDryRunWarning($count, $zsetKey);

            return self::SUCCESS;
        }

        return $this->forcePurge($redis, $zsetKey, $canonical);
    }

    private function printDryRunWarning(int $count, string $zsetKey): void
    {
        $this->newLine();
        $this->warn(sprintf(
            'About to destroy %d pending entr%s on %s. This command is intended for the pre-0.16 orphan-cleanup case ONLY. If %s the live queue for this connection (not an orphan key), --force will shred legitimate in-flight pending tracking. Verify before re-running.',
            $count,
            $count === 1 ? 'y' : 'ies',
            $zsetKey,
            $count === 1 ? 'this entry belongs to' : 'these entries belong to',
        ));
        $this->line('<fg=yellow>Dry-run.</> Re-run with --force to actually delete.');
    }

    /**
     * RENAME the zset to a per-run temp key BEFORE walking it. Atomic
     * snapshot — any producer still writing to the original key path
     * (mis-configured fix, stragglers, etc.) creates a fresh zset and
     * is left undisturbed; we only ever delete the renamed snapshot.
     * Also defends against the ZRANGE-vs-DEL window: a member added
     * after the read but before the original DEL would have been
     * silently nuked by the old path. Now they land on the new zset
     * that we never touch.
     */
    private function forcePurge(RedisConnection $redis, string $zsetKey, string $canonical): int
    {
        $tempKey = $zsetKey . ':purging-' . bin2hex(random_bytes(4));
        try {
            $redis->command('rename', [$zsetKey, $tempKey]);
        } catch (Throwable $e) {
            $this->error("Failed to snapshot {$zsetKey} via RENAME: {$e->getMessage()}");

            return self::FAILURE;
        }

        $hashesDeleted = $this->deleteMatchingHashes($redis, $tempKey, $canonical);
        $snapshotCard = $redis->command('zcard', [$tempKey]);
        $snapshotCount = is_numeric($snapshotCard) ? (int) $snapshotCard : 0;
        $zsetDelReply = $redis->command('del', [$tempKey]);
        $zsetDeleted = is_numeric($zsetDelReply) ? (int) $zsetDelReply : 0;

        $this->newLine();
        $this->info(sprintf(
            'Purged %d zset member%s + %d matching pending:{uuid} hash%s.',
            $snapshotCount,
            $snapshotCount === 1 ? '' : 's',
            $hashesDeleted,
            $hashesDeleted === 1 ? '' : 'es',
        ));
        $this->line(sprintf('snapshot key deleted: %s', $zsetDeleted === 1 ? 'yes' : 'no (already gone)'));

        return self::SUCCESS;
    }

    /**
     * Walk the zset's members and delete the per-uuid hash for each one
     * whose `queue` field matches the target queue. Skips hashes that
     * are missing (already TTL'd) or whose `queue` field points at a
     * different queue (defensive — orphan zset shouldn't contain
     * cross-queue uuids, but the field-match guard makes this command
     * safe to re-run after a snapshots-config flip).
     *
     * `ZRANGE 0 -1` returns every member in one shot; the zset is bounded
     * by `pending.max_per_queue` (default 10 000), so the worst-case read
     * is a single ~250 KB Redis reply. Avoids ZSCAN whose reply shape
     * diverges between predis (flat `[member, score, ...]`) and phpredis
     * (assoc `[member => score]`).
     */
    private function deleteMatchingHashes(RedisConnection $redis, string $zsetKey, string $canonicalQueue): int
    {
        $members = $redis->command('zrange', [$zsetKey, 0, -1]);
        if (! is_array($members) || $members === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($members as $uuid) {
            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            $hashKey = KeyPrefix::make("pending:{$uuid}");
            $storedQueue = $redis->command('hget', [$hashKey, 'queue']);
            if (! is_string($storedQueue) || $storedQueue !== $canonicalQueue) {
                continue;
            }

            $delReply = $redis->command('del', [$hashKey]);
            $deleted += is_numeric($delReply) ? (int) $delReply : 0;
        }

        return $deleted;
    }
}
