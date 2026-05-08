<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use Throwable;

final readonly class RecordScheduledTaskFinished
{
    public function __construct(
        private RunStore $store,
        private OutputCapturer $capturer,
    ) {}

    public function handle(ScheduledTaskFinished $event): void
    {
        $taskKey = null;

        try {
            $task = $event->task;
            $taskKey = TaskKey::for($task);

            // For background tasks this `Finished` dispatch fires in the
            // PARENT `schedule:run` immediately after `$event->run()`
            // returns from spawning the child. At that moment `$task->
            // exitCode` is unset and the runtime measures spawn-time
            // only — recording finish here would clobber the running
            // pointer with bogus metrics and starve the real
            // `ScheduledBackgroundTaskFinished` listener (in the child)
            // of its source. Skip; the child writes the truth.
            if ((bool) $task->runInBackground) {
                return;
            }

            $running = $this->store->readRunning($taskKey);
            if ($running === null) {
                // Missed Starting (rare — listener exception, host
                // dropped, Redis blip evicted the running pointer).
                // Sweeper picks the gap up via missed-run detection.
                return;
            }

            $finishedAt = Date::now()->getTimestampMs();
            $runtimeMs = (int) round($event->runtime * 1000);
            $rawExitCode = $task->exitCode;
            $exitCode = is_int($rawExitCode) ? $rawExitCode : 0;
            $status = $exitCode === 0 ? 'success' : 'failed';

            $this->store->recordFinish([
                'task_key' => $taskKey,
                'run_id' => $running['run_id'],
                'finished_at_ms' => $finishedAt,
                'runtime_ms' => $runtimeMs,
                'exit_code' => $exitCode,
                'status' => $status,
                'output' => $this->capturer->capture($task),
                'exception' => null,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskFinished failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        } finally {
            // Always pop the frame Starting pushed — early returns above
            // would otherwise leak it across subsequent events in the same
            // `schedule:run` worker (poisoning queue↔schedule attribution
            // for jobs queued during later tasks).
            if (is_string($taskKey)) {
                ScheduleContext::pop($taskKey);
            }
        }
    }
}
