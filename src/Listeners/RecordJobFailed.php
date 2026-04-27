<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use Throwable;

final readonly class RecordJobFailed
{
    public function __construct(
        private ResolveJobClass $resolveJobClass,
    ) {}

    public function handle(JobFailed $event): void
    {
        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $connectionName = (string) $event->connectionName;
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::from($queueRaw === '' ? 'default' : $queueRaw);

            $class = $this->resolveJobClass->from($event->job, $connectionName, $queueKey);

            $now = CarbonImmutable::now('UTC');
            $nowTs = $now->getTimestamp();
            $bucket = $now->format('YmdH');
            $isoNow = $now->toIso8601String();

            $bucketStart = CarbonImmutable::createFromFormat('YmdH', $bucket, 'UTC');
            $bucketTs = $bucketStart instanceof CarbonImmutable
                ? $bucketStart->startOfHour()->getTimestamp()
                : $now->startOfHour()->getTimestamp();

            $failedKey = KeyPrefix::make("failed:{$class}:{$bucket}");
            $redis->command('incr', [$failedKey]);
            $redis->command('expireat', [$failedKey, $bucketTs + (30 * 86400)]);

            $uuid = $event->job->uuid();
            if ($uuid !== null && $uuid !== '') {
                $redis->command('del', [KeyPrefix::make("start:{$uuid}")]);

                // Belt-and-suspenders pending-tracking cleanup. RecordJobProcessing
                // already cleared on the pending → in-flight transition; this is
                // here for the rare case where that listener was missed.
                if (Config::bool('pending.enabled', true)) {
                    $redis->command('del', [KeyPrefix::make("pending:{$uuid}")]);
                    $redis->command('zrem', [KeyPrefix::make("pending-zset:{$connectionName}:{$queueKey}"), $uuid]);
                    // Inflight-zset entry was added by RecordJobProcessing.
                    $redis->command('zrem', [KeyPrefix::make("inflight-zset:{$connectionName}:{$queueKey}"), $uuid]);
                }

                // Batch tracking — index uuid → failed_jobs row id. JobFailed
                // fires AFTER FailedJobProvider::log() inserts the row, so the
                // lookup-by-uuid is safe. Modal opens by row id (openFailed),
                // so the batch-detail view needs the id to wire the click.
                if (Config::bool('batches.enabled', true)) {
                    $failedJobsId = $this->resolveFailedJobId($uuid);
                    if ($failedJobsId !== null) {
                        $redis->command('setex', [
                            KeyPrefix::make("uuid-failed:{$uuid}"),
                            Config::int('batches.ttl_seconds', 604800),
                            (string) $failedJobsId,
                        ]);
                    }
                }
            }

            $redis->command('zadd', [KeyPrefix::make('classes'), $nowTs, $class]);
            $redis->command('setex', [KeyPrefix::make("last_run:{$class}"), 2592000, $isoNow]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobFailed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Look up the failed_jobs row id for a uuid. Wrapped in try/catch so a
     * non-default failed-job storage (custom provider, missing table) only
     * silently skips the batch-tracking write — never breaks the listener.
     *
     * `orderByDesc('id')` because Laravel's `DatabaseUuidFailedJobProvider`
     * inserts a fresh row on every JobFailed, so a uuid that's been retried
     * and failed again has multiple rows. The just-inserted one (highest id)
     * is the one this event is for; without the order-by we'd index the
     * oldest row and the batch-detail click would open the wrong failure.
     */
    private function resolveFailedJobId(string $uuid): ?int
    {
        try {
            $id = DB::table('failed_jobs')->where('uuid', $uuid)->orderByDesc('id')->value('id');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }
}
