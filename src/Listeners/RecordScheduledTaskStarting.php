<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Scheduler\HostId;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

final readonly class RecordScheduledTaskStarting
{
    public function __construct(private RunStore $store) {}

    public function handle(ScheduledTaskStarting $event): void
    {
        try {
            $task = $event->task;
            $taskKey = TaskKey::for($task);
            $runId = (string) Str::ulid();
            $now = Date::now()->getTimestampMs();

            $expectedRuntimeMs = $this->store->recentP95RuntimeMs($taskKey)
                ?? Config::int('scheduler.hung.grace_seconds', 300) * 1000;

            $expectedFinish = $now
                + $expectedRuntimeMs
                + Config::int('scheduler.hung.grace_seconds', 300) * 1000;

            $this->store->recordStarting([
                'task_key' => $taskKey,
                'run_id' => $runId,
                'started_at_ms' => $now,
                'host_id' => HostId::resolve(),
                'is_background' => (bool) $task->runInBackground,
                'expected_finish_at_ms' => $expectedFinish,
            ]);

            // Push the active frame so jobs queued inside the task's
            // run get attributed back via `RecordJobQueued`.
            ScheduleContext::push($taskKey, $runId);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskStarting failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
