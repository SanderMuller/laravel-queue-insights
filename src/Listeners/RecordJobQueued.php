<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * Records the enqueue timestamp for a job so the worker-side
 * RecordJobProcessing handler can compute wait_ms.
 *
 * Join key: `payload.uuid`, NOT `JobQueued::$id`. Per
 * specs/horizon-inspired-features.md §2.2, `$event->id` is the
 * driver-generated identifier (Redis stream id / DB row PK / SQS
 * message id), which is *not* the same identifier returned by
 * `$event->job->uuid()` later in `RecordJobProcessing`. Decoding the
 * payload and reading `payload.uuid` is the only portable join key.
 */
final class RecordJobQueued
{
    public function handle(JobQueued $event): void
    {
        try {
            $payloadRaw = $event->payload;
            if (! is_string($payloadRaw) || $payloadRaw === '') {
                return;
            }

            $payload = json_decode($payloadRaw, true);
            $uuid = is_array($payload) && isset($payload['uuid']) && is_string($payload['uuid'])
                ? $payload['uuid']
                : null;

            if ($uuid === null || $uuid === '') {
                // Payload-without-uuid path — drivers / Laravel versions that
                // don't stamp a uuid into the payload. The modal renders `—`
                // for that job's wait time.
                return;
            }

            Redis::connection(Config::string('redis_connection', 'default'))->command('setex', [
                KeyPrefix::make("pushed:{$uuid}"),
                3600,
                (string) microtime(true),
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobQueued failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
