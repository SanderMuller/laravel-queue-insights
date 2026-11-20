<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageClaim;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ListenerFailureReporter;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

final class RecordJobProcessing
{
    public function handle(JobProcessing $event): void
    {
        try {
            $uuid = $event->job->uuid();

            if ($uuid === null || $uuid === '') {
                return;
            }

            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            // Canonicalise — see ConnectionAlias docblock.
            $connection = ConnectionAlias::canonical((string) $event->connectionName);
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::fromOrDefault($queueRaw, $connection);

            $now = microtime(true);

            // Two round trips: the reads the writes depend on, then every
            // write in one pipeline.
            [$pushedRaw, $parentUuid] = $this->readPushedAndLineage($redis, $uuid);

            $batch = new RedisBatch($redis);

            // Chain-claim ticket runs before child-fire so the child's
            // JobQueued listener can RPOP it (Phase 0 ordering proof).
            $this->pushChainClaim($batch, $event, $uuid, $connection, $queueRaw, $queueKey);

            // Copy `qi:lineage:{uuid}` to the pending hash so the in-flight
            // modal renders `↰ From {uuid}` before the durable stream row
            // exists.
            if ($parentUuid !== null) {
                $batch->command('hset', [KeyPrefix::make("pending:{$uuid}"), 'parent_uuid', $parentUuid]);
            }

            // Pending → in-flight transition. attempts ≥2 on retries /
            // release() — stamped so the row template can flag a retry without
            // touching the failed_jobs table.
            $attempts = (int) $event->job->attempts();
            $this->markInFlight($batch, $uuid, $connection, $queueKey, (int) $now, $attempts);
            $batch->command('setex', [
                KeyPrefix::make("start:{$uuid}"),
                3600,
                (string) $now,
            ]);

            $this->writeWaitSample($batch, $pushedRaw, $now, $uuid, $connection, $queueKey);

            $batch->execute();
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessing failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'redis_connection' => Config::string('redis_connection', 'default'),
            ]);

            resolve(ListenerFailureReporter::class)->report('RecordJobProcessing', $throwable);
        }
    }

    /**
     * Move a uuid from pending → in-flight: stamp `state` + `started_at` on
     * the existing `pending:{uuid}` hash, drop it from `pending-zset`, and
     * add it to `inflight-zset` (score = start time so the dashboard can
     * order by "started Xs ago" and surface stuck jobs).
     *
     * The full transition is wrapped in a Lua script so all five mutations
     * (hash stamp, hash EXPIRE, pending ZREM, inflight ZADD, inflight EXPIRE)
     * land atomically. A non-atomic version could leave a running job
     * visible in neither group if a connection drop hit between ZREM and
     * ZADD — the dashboard would silently lose a live job. Lua executes
     * server-side, so the worst case is the script fails entirely (caller
     * catches and logs) and the row stays in pending until the next attempt
     * or TTL.
     *
     * Reusing the same hash keeps the per-uuid metadata (class, connection,
     * queue, queued_at, batch_id) addressable for the in-flight modal
     * without a second write path. Cleanup on Processed/Failed deletes the
     * hash + both zset entries.
     */
    private function markInFlight(RedisBatch $batch, string $uuid, string $connection, string $queueKey, int $startedAt, int $attempts): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $batch->eval(
            LuaScripts::markInFlight(),
            4,
            KeyPrefix::make("pending:{$uuid}"),
            KeyPrefix::make("pending-zset:{$connection}:{$queueKey}"),
            KeyPrefix::make("inflight-zset:{$connection}:{$queueKey}"),
            KeyPrefix::make("pending-orphans:{$connection}:{$queueKey}"),
            $uuid,
            (string) $startedAt,
            (string) Config::int('pending.ttl_seconds', 86400),
            (string) max(1, $attempts),
        );
    }

    /**
     * Read `pushed:{uuid}` (for the wait sample) and, when lineage feeds the
     * pending hash, `qi:lineage:{uuid}`, in one round trip. The lineage read
     * goes to its own connection when `chain_lineage.redis_connection`
     * points elsewhere.
     *
     * @return array{mixed, ?string}
     */
    private function readPushedAndLineage(RedisConnection $redis, string $uuid): array
    {
        $lineage = new ChainLineageStore();
        $wantsLineage = Config::bool('chain_lineage.enabled', true) && Config::bool('pending.enabled', true);
        $batched = $wantsLineage && $lineage->usesPrimaryConnection();

        $reads = new RedisBatch($redis);
        $reads->command('get', [KeyPrefix::make("pushed:{$uuid}")]);
        if ($batched) {
            $reads->command('get', [$lineage->lineageKey($uuid)]);
        }

        $results = $reads->execute();

        $parent = match (true) {
            $batched => $results[1] ?? null,
            $wantsLineage => ChainLineageStore::bestEffort('RecordJobProcessing', static fn (): ?string => $lineage->readLineage($uuid)),
            default => null,
        };

        return [$results[0] ?? null, is_string($parent) && $parent !== '' ? $parent : null];
    }

    /**
     * Wait-time capture from the `pushed:{uuid}` timestamp RecordJobQueued
     * wrote. A missing key is the legacy / custom-driver case (no JobQueued
     * listener when the job was pushed, or no payload.uuid); the modal
     * renders `—` for that job.
     */
    private function writeWaitSample(RedisBatch $batch, mixed $pushedRaw, float $now, string $uuid, string $connection, string $queueKey): void
    {
        if (! is_string($pushedRaw) || ! is_numeric($pushedRaw)) {
            return;
        }

        $waitMs = max(0, (int) round(($now - (float) $pushedRaw) * 1000));

        // Cross-host clock-skew guard. `pushed_at` comes from the producer
        // host, `$now` from the worker host. Negative skew is clamped above;
        // anything over 7 days is bogus and would poison p50/p95 for the
        // full retention window.
        if ($waitMs > 604800000) {
            return;
        }

        // Per-job wait sample, kept for the 7d retention window so the modal
        // can render `Wait: …` for jobs in the recent-completed / failed views.
        $batch->command('setex', [
            KeyPrefix::make("wait:{$uuid}"),
            604800,
            (string) $waitMs,
        ]);

        // Per-queue rolling sample set. Score is `$now` (insertion ts) so the
        // trim drops the oldest entries; scoring by wait_ms would drop the
        // fastest jobs and skew p50/p95. `queueWaitPercentiles` MGETs
        // `wait:{uuid}` for the members.
        $waitKey = KeyPrefix::make("wait:{$connection}:{$queueKey}");

        $batch->command('zadd', [$waitKey, $now, $uuid]);
        $batch->command('zremrangebyrank', [$waitKey, 0, -1001]);
        $batch->command('expire', [$waitKey, 604800]);
    }

    /**
     * Decode the parent's serialized command for chain context. If the parent
     * is the head of a chain (`chained` non-empty), push a claim ticket onto
     * the per-(connection, queue, next-class, tail-fingerprint) FIFO list
     * so the next link's `JobQueued` listener can RPOP it and stamp
     * parent_uuid.
     *
     * No-op when:
     *   - chain lineage is disabled
     *   - the payload is encrypted (extractChainContext returns null)
     *   - the parent has no chained tail (last link / non-chain dispatch)
     */
    private function pushChainClaim(RedisBatch $batch, JobProcessing $event, string $uuid, string $defaultConnection, string $defaultQueueRaw, string $defaultQueueKey): void
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return;
        }

        try {
            $payload = $event->job->payload();
            $command = is_array($payload['data'] ?? null)
                ? ($payload['data']['command'] ?? null)
                : null;

            if (! is_string($command) || $command === '') {
                return;
            }

            $context = SerializedCommandReader::extractChainContext($command);
            if ($context === null) {
                return;
            }

            $tailClasses = [];
            foreach (array_slice($context['jobs'], 1) as $job) {
                $tailClasses[] = $job['class'];
            }

            // Chain-context can override the outer connection/queue (e.g.
            // `Bus::chain(...)->onConnection('foo')`). When both overrides
            // are null — the default-Bus::chain case where SerializesModels
            // strips them — reuse the caller's already-canonicalised values
            // to skip a config walk. Any override drops us back to the full
            // resolution so the chain-claim key matches what the child's
            // `RecordJobQueued::chainClaimKey` will compute on the
            // overridden connection (its config-default queue, if any).
            $chainConnection = $context['chain_connection'];
            $chainQueue = $context['chain_queue'];
            if ($chainConnection === null && $chainQueue === null) {
                $connection = $defaultConnection;
                $queueKey = $defaultQueueKey;
            } else {
                // Canonicalise the override so the claim key matches what
                // the child's `RecordJobQueued::chainClaimKey` will
                // compute (the child's `$event->connectionName` is also
                // canonicalised at the listener top). Without this,
                // `Bus::chain(...)->onConnection('redis')` would push a
                // claim under raw `redis` while the child pops under the
                // canonical alias.
                $connection = $chainConnection !== null
                    ? ConnectionAlias::canonical($chainConnection)
                    : $defaultConnection;
                $queueRaw = $chainQueue ?? $defaultQueueRaw;
                $queueKey = CanonicalQueueKey::fromOrDefault($queueRaw, $connection);
            }

            $key = ChainLineageClaim::key($connection, $queueKey, $context['next_class'], $tailClasses);

            $ttl = Config::int('chain_lineage.claim_ttl_seconds', 60);
            $store = new ChainLineageStore();
            if (! $store->usesPrimaryConnection()) {
                $store->pushClaim($key, $uuid, $ttl);

                return;
            }

            if ($ttl > 0) {
                $batch->eval(LuaScripts::pushChainClaim(), 1, $key, $uuid, (string) $ttl);
            }
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: pushChainClaim failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'redis_connection' => Config::string('redis_connection', 'default'),
            ]);
        }
    }
}
