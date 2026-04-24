<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
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
}
