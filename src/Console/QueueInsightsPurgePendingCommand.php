<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisPipeline;
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
    /** Hash reads + deletes are pipelined in chunks of this size. */
    private const int PIPELINE_CHUNK = 500;

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
            // `from()`, NOT `forConnection()`: the argument names a key as it
            // is actually stored, and orphans are exactly the keys that don't
            // match what the current code would derive — a suffix-stripping
            // pass here would retarget the command away from them.
            $canonical = CanonicalQueueKey::from($queueRaw);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error("Invalid queue value [{$queueRaw}]: {$invalidArgumentException->getMessage()}");

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

        return $this->forcePurge($redis, $zsetKey, $canonical, $count);
    }

    private function printDryRunWarning(int $count, string $zsetKey): void
    {
        $this->newLine();
        $this->warn(sprintf(
            'About to destroy %d pending %s on %s. This command is intended for the pre-0.16 orphan-cleanup case ONLY. If %s the live queue for this connection (not an orphan key), --force will shred legitimate in-flight pending tracking. Verify before re-running.',
            $count,
            Str::plural('entry', $count),
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
    private function forcePurge(RedisConnection $redis, string $zsetKey, string $canonical, int $count): int
    {
        $tempKey = $zsetKey . ':purging-' . bin2hex(random_bytes(4));
        try {
            $redis->command('rename', [$zsetKey, $tempKey]);
        } catch (Throwable $throwable) {
            $this->error("Failed to snapshot {$zsetKey} via RENAME: {$throwable->getMessage()}");

            return self::FAILURE;
        }

        $hashesDeleted = $this->deleteMatchingHashes($redis, $tempKey, $canonical);
        $zsetDelReply = $redis->command('del', [$tempKey]);
        $zsetDeleted = is_numeric($zsetDelReply) ? (int) $zsetDelReply : 0;

        $this->newLine();
        $this->info(sprintf(
            'Purged %d zset %s + %d matching pending:{uuid} %s.',
            $count,
            Str::plural('member', $count),
            $hashesDeleted,
            Str::plural('hash', $hashesDeleted),
        ));
        $this->line(sprintf('snapshot key deleted: %s', $zsetDeleted === 1 ? 'yes' : 'no (already gone)'));

        return self::SUCCESS;
    }

    /**
     * Only hashes whose stored `queue` field matches `$canonicalQueue` are
     * deleted — defensive against partial re-runs after a snapshots-config
     * flip, where the orphan zset MAY no longer be one-to-one with hashes.
     *
     * ZRANGE 0 -1 over ZSCAN: predis returns a flat `[member, score, …]`
     * list while phpredis returns an assoc `[member => score]` map for
     * ZSCAN; ZRANGE returns positional members under both drivers.
     */
    private function deleteMatchingHashes(RedisConnection $redis, string $zsetKey, string $canonicalQueue): int
    {
        $members = $redis->command('zrange', [$zsetKey, 0, -1]);
        if (! is_array($members) || $members === []) {
            return 0;
        }

        $uuids = array_values(array_filter(
            $members,
            static fn (mixed $u): bool => is_string($u) && $u !== '',
        ));
        if ($uuids === []) {
            return 0;
        }

        $toDelete = $this->matchingUuids($redis, $uuids, $canonicalQueue);

        return $toDelete === [] ? 0 : $this->pipelinedDelete($redis, $toDelete);
    }

    /**
     * Pipelined `HGET pending:{uuid} queue` over each chunk; returns only
     * the uuids whose stored queue matches `$canonicalQueue`.
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    private function matchingUuids(RedisConnection $redis, array $uuids, string $canonicalQueue): array
    {
        $matches = [];
        foreach (array_chunk($uuids, self::PIPELINE_CHUNK) as $chunk) {
            $replies = RedisPipeline::run($redis, static function (mixed $pipe) use ($chunk): void {
                foreach ($chunk as $uuid) {
                    $pipe->hget(KeyPrefix::make("pending:{$uuid}"), 'queue');
                }
            });
            foreach ($chunk as $i => $uuid) {
                $stored = $replies[$i] ?? null;
                if (is_string($stored) && $stored === $canonicalQueue) {
                    $matches[] = $uuid;
                }
            }
        }

        return $matches;
    }

    /**
     * Pipelined `DEL pending:{uuid}` over each chunk; returns the total
     * number of hashes Redis reported as actually deleted.
     *
     * @param  list<string>  $uuids
     */
    private function pipelinedDelete(RedisConnection $redis, array $uuids): int
    {
        $deleted = 0;
        foreach (array_chunk($uuids, self::PIPELINE_CHUNK) as $chunk) {
            $replies = RedisPipeline::run($redis, static function (mixed $pipe) use ($chunk): void {
                foreach ($chunk as $uuid) {
                    $pipe->del(KeyPrefix::make("pending:{$uuid}"));
                }
            });
            foreach ($replies as $reply) {
                if (is_numeric($reply)) {
                    $deleted += (int) $reply;
                }
            }
        }

        return $deleted;
    }
}
