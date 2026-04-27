<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
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
            $queue = (string) ($event->job->getQueue() ?? 'default');

            // Use SETEX (key, ttl, value) — same 3-arg signature on phpredis and Predis.
            // `SET key val EX ttl` has divergent arg shapes across drivers.
            $now = microtime(true);

            // Pending → in-flight transition runs before the wait-time path so
            // it isn't accidentally skipped by an early return below (missing
            // pushed key, clock-skew rejection). If the worker successfully
            // picked up a job, it's in-flight regardless.
            $this->markInFlight($redis, $uuid, $connection, $queue, (int) $now);
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

            // Per-queue rolling sample set keyed for **recency**, not value.
            //   member = uuid (unique per job)
            //   score  = $now (insertion timestamp)
            // Trim drops the oldest 1000+ by score, keeping the most recent
            // 1000. Percentile reads (queueWaitPercentiles) iterate members
            // and MGET `wait:{uuid}` to recover wait_ms.
            // Naive `score = wait_ms` would have made trim drop the fastest
            // jobs and skew p50/p95 toward outliers — codex review.
            $waitKey = KeyPrefix::make("wait:{$connection}:{$queue}");

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
    private function markInFlight(RedisConnection $redis, string $uuid, string $connection, string $queue, int $startedAt): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        // CanonicalQueueKey on the cleanup side mirrors the writer in
        // RecordJobQueued, so the zset key matches even when the producer
        // saw a queue URL (SQS) and the worker reports the plain name.
        $queueKey = $queue === '' ? 'default' : CanonicalQueueKey::from($queue);

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
        );
    }
}
