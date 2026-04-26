<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
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

            // Use SETEX (key, ttl, value) — same 3-arg signature on phpredis and Predis.
            // `SET key val EX ttl` has divergent arg shapes across drivers.
            $now = microtime(true);
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
            $connection = (string) $event->connectionName;
            $queue = (string) ($event->job->getQueue() ?? 'default');
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
}
