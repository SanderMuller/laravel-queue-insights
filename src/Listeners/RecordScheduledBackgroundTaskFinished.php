<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use Throwable;

/**
 * Fires from `schedule:finish {id} {exitCode}` after a `runInBackground`
 * child process exits. The child must have flushed `$task->output` to a
 * file the listener can still read — `sendOutputTo()` / `storeOutput()`
 * is what makes that work; without it, capture mode `full` returns
 * null and operators see exit-code-only.
 */
final readonly class RecordScheduledBackgroundTaskFinished
{
    public function __construct(
        private RunStore $store,
        private OutputCapturer $capturer,
    ) {}

    public function handle(ScheduledBackgroundTaskFinished $event): void
    {
        try {
            $task = $event->task;
            $taskKey = TaskKey::for($task);

            $running = $this->store->readRunning($taskKey);
            if ($running === null) {
                return;
            }

            $finishedAt = Date::now()->getTimestampMs();
            $runtimeMs = max(0, $finishedAt - $running['started_at_ms']);
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
            Log::warning('queue-insights: RecordScheduledBackgroundTaskFinished failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
