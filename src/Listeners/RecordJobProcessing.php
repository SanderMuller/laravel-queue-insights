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

            Redis::connection(Config::string('redis_connection', 'default'))->command('set', [
                KeyPrefix::make("start:{$uuid}"),
                (string) microtime(true),
                'EX',
                3600,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessing failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
