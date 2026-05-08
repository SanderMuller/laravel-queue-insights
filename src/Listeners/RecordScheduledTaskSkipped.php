<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Scheduler\HostId;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\SkipReasonResolver;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use Throwable;

final readonly class RecordScheduledTaskSkipped
{
    public function __construct(
        private RunStore $store,
        private Application $app,
    ) {}

    public function handle(ScheduledTaskSkipped $event): void
    {
        try {
            $task = $event->task;
            $taskKey = TaskKey::for($task);
            $runId = (string) Str::ulid();
            $atMs = Date::now()->getTimestampMs();
            $reason = SkipReasonResolver::resolve($task, $this->app);

            $this->store->recordSkipped($taskKey, $runId, $atMs, $reason, HostId::resolve());
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskSkipped failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
