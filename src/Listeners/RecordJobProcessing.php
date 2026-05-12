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
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
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

            $connection = (string) $event->connectionName;
            $queueKey = CanonicalQueueKey::fromOrDefault((string) $event->job->getQueue(), $connection);

            // Use SETEX (key, ttl, value) — same 3-arg signature on phpredis and Predis.
            // `SET key val EX ttl` has divergent arg shapes across drivers.
            $now = microtime(true);

            // Chain-claim ticket runs before child-fire so the child's
            // JobQueued listener can RPOP it (Phase 0 ordering proof).
            $this->pushChainClaim($event, $uuid, $connection, $queueKey);

            // Copy `qi:lineage:{uuid}` to the pending hash so the in-flight
            // modal renders `↰ From {uuid}` before the durable stream row
            // exists.
            $this->copyLineageToPending($redis, $uuid);

            // Pending → in-flight transition runs before the wait-time path so
            // it isn't accidentally skipped by an early return below (missing
            // pushed key, clock-skew rejection). attempts ≥2 on retries /
            // release() — stamped so the row template can flag a retry without
            // touching the failed_jobs table.
            $attempts = (int) $event->job->attempts();
            $this->markInFlight($redis, $uuid, $connection, $queueKey, (int) $now, $attempts);
            $redis->command('setex', [
                KeyPrefix::make("start:{$uuid}"),
                3600,
                (string) $now,
            ]);

            // Wait-time capture — derive from the pushed:{uuid} key written by
            // RecordJobQueued. Missing-key path is the legacy / custom-driver
            // case (no JobQueued listener was active when the job was pushed,
            // or the driver omitted payload.uuid); render `—` for that job.
            $pushedRaw = $redis->command('get', [KeyPrefix::make("pushed:{$uuid}")]);
            if (! is_string($pushedRaw) || ! is_numeric($pushedRaw)) {
                return;
            }

            $waitMs = max(0, (int) round(($now - (float) $pushedRaw) * 1000));

            // Cross-host clock-skew guard. The `pushed_at` timestamp comes
            // from the producer host, `$now` from the worker host. With NTP
            // drift either direction, raw delta can be wildly wrong.
            // Negative skew is already clamped to 0 above. Positive skew is
            // bounded here at 7 days — anything larger is bogus and would
            // poison p50/p95 for the full retention window. (Codex review.)
            if ($waitMs > 604800000) {
                return;
            }

            // Per-job wait sample. TTL = retention window (7d) so the modal
            // can render `Wait: …` for any job still in the recent-completed
            // / failed views.
            $redis->command('setex', [
                KeyPrefix::make("wait:{$uuid}"),
                604800,
                (string) $waitMs,
            ]);

            // Per-queue rolling wait-time sample set. Score is `$now`
            // (insertion ts) so trim drops oldest entries; using wait_ms as
            // score would drop the fastest jobs and skew p50/p95 (codex).
            // Members are uuids; `queueWaitPercentiles` MGETs `wait:{uuid}`.
            $waitKey = KeyPrefix::make("wait:{$connection}:{$queueKey}");

            $redis->command('zadd', [$waitKey, $now, $uuid]);
            $redis->command('zremrangebyrank', [$waitKey, 0, -1001]);
            $redis->command('expire', [$waitKey, 604800]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessing failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
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
    private function markInFlight(RedisConnection $redis, string $uuid, string $connection, string $queueKey, int $startedAt, int $attempts): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::markInFlight(),
            3,
            KeyPrefix::make("pending:{$uuid}"),
            KeyPrefix::make("pending-zset:{$connection}:{$queueKey}"),
            KeyPrefix::make("inflight-zset:{$connection}:{$queueKey}"),
            $uuid,
            (string) $startedAt,
            (string) Config::int('pending.ttl_seconds', 86400),
            (string) max(1, $attempts),
        );
    }

    /**
     * Copy `qi:lineage:{uuid}` into the pending hash so the in-flight modal
     * can render the parent_uuid before the job completes (and before the
     * durable stream row exists). Idempotent: HSET overwrites the existing
     * pending-hash field with the same value, no harm on retry. Skipped when
     * pending tracking is disabled or the interim hash is empty.
     */
    private function copyLineageToPending(RedisConnection $redis, string $uuid): void
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return;
        }

        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $parent = (new ChainLineageStore())->readLineage($uuid);
        if ($parent === null) {
            return;
        }

        $redis->command('hset', [
            KeyPrefix::make("pending:{$uuid}"),
            'parent_uuid',
            $parent,
        ]);
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
    private function pushChainClaim(JobProcessing $event, string $uuid, string $defaultConnection, string $defaultQueueKey): void
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
            // `Bus::chain(...)->onConnection('foo')`). When it doesn't —
            // the common default-Bus::chain case where SerializesModels
            // strips the null override — fall through to the caller's
            // already-canonicalised values to skip a second config walk.
            $chainConnection = $context['chain_connection'];
            $chainQueue = $context['chain_queue'];
            if ($chainConnection === null && $chainQueue === null) {
                $connection = $defaultConnection;
                $queueKey = $defaultQueueKey;
            } else {
                $connection = $chainConnection ?? $defaultConnection;
                $queueKey = $chainQueue !== null
                    ? CanonicalQueueKey::fromOrDefault($chainQueue, $connection)
                    : $defaultQueueKey;
            }

            $key = ChainLineageClaim::key($connection, $queueKey, $context['next_class'], $tailClasses);

            (new ChainLineageStore())->pushClaim(
                $key,
                $uuid,
                Config::int('chain_lineage.claim_ttl_seconds', 60),
            );
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: pushChainClaim failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
