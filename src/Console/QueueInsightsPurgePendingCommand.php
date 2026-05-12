<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

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
        {--force : Actually delete. Without this flag the command is a dry-run.}';

    protected $description = 'Purge a pending-tracking zset + matching per-uuid hashes for a single (connection, queue) pair. Use for post-upgrade orphan cleanup.';

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

        $canonical = CanonicalQueueKey::from($queueRaw);
        $zsetKey = KeyPrefix::make("pending-zset:{$connection}:{$canonical}");
        $redis = Redis::connection(Config::string('redis_connection', 'default'));

        $zcard = $redis->command('zcard', [$zsetKey]);
        $count = is_numeric($zcard) ? (int) $zcard : 0;

        if ($count === 0) {
            $this->info("No pending entries on {$zsetKey} — nothing to purge.");

            return self::SUCCESS;
        }

        $sample = $redis->command('zrange', [$zsetKey, 0, 4]);
        $uuids = is_array($sample) ? array_values(array_filter(
            $sample,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )) : [];

        $this->line("zset    : {$zsetKey}");
        $this->line("members : {$count}");
        if ($uuids !== []) {
            $this->line('sample  : ' . implode(', ', array_slice($uuids, 0, 5)) . ($count > count($uuids) ? ', …' : ''));
        }

        $force = (bool) $this->option('force');
        if (! $force) {
            $this->newLine();
            $this->line('<fg=yellow>Dry-run.</> Re-run with --force to actually delete.');

            return self::SUCCESS;
        }

        $hashesDeleted = $this->deleteMatchingHashes($redis, $zsetKey, $canonical);
        $zsetDelReply = $redis->command('del', [$zsetKey]);
        $zsetDeleted = is_numeric($zsetDelReply) ? (int) $zsetDelReply : 0;

        $this->newLine();
        $this->info(sprintf(
            'Purged %d zset member%s + %d matching pending:{uuid} hash%s.',
            $count,
            $count === 1 ? '' : 's',
            $hashesDeleted,
            $hashesDeleted === 1 ? '' : 'es',
        ));
        $this->line(sprintf('zset key deleted: %s', $zsetDeleted === 1 ? 'yes' : 'no (already gone)'));

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
