<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Events\ScheduledTaskFailed as ScheduledTaskFailedDomainEvent;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Scheduler\SchedulerCooldown;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

final readonly class RecordScheduledTaskFailed
{
    public function __construct(
        private RunStore $store,
        private OutputCapturer $capturer,
        private SchedulerCooldown $cooldown,
    ) {}

    public function handle(ScheduledTaskFailed $event): void
    {
        $taskKey = null;

        try {
            $task = $event->task;
            $taskKey = TaskKey::for($task);
            $exception = $event->exception;
            $exceptionPayload = [
                'class' => $exception::class,
                'message' => $this->truncate($exception->getMessage(), 2000),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace_tail' => $this->truncate($exception->getTraceAsString(), 4000),
            ];

            $running = $this->store->readRunning($taskKey);

            if ($running === null) {
                // Laravel's `ScheduleRunCommand::handle` dispatches
                // BOTH `Finished` and `Failed` for foreground failures
                // (Finished first, throws on non-zero exit, catch then
                // dispatches Failed). At this point Finished's
                // `recordFinish` already deleted the running pointer
                // and wrote a `status=failed` run row. Enrich that row
                // with exception data instead of synthesizing a second
                // run + double-counting `total_failed`.
                $existingRunId = $this->store->recentlyFinishedRunId($taskKey);
                if ($existingRunId !== null) {
                    $this->store->stampException($taskKey, $existingRunId, $exceptionPayload);
                    $this->maybeFireDomainEvent($taskKey, $existingRunId, $event);

                    return;
                }
            }

            // No prior Finished — Starting fired but Finished's listener
            // never ran (rare: listener exception, dispatcher mid-flight).
            // Synthesize a one-shot run record so the failure stays
            // queryable.
            $runId = $running['run_id'] ?? (string) Str::ulid();

            $finishedAt = Date::now()->getTimestampMs();
            $startedAt = $running['started_at_ms'] ?? $finishedAt;
            $runtimeMs = max(0, $finishedAt - $startedAt);
            $rawExitCode = $task->exitCode;
            $exitCode = is_int($rawExitCode) ? $rawExitCode : -1;

            $this->store->recordFinish([
                'task_key' => $taskKey,
                'run_id' => $runId,
                'finished_at_ms' => $finishedAt,
                'runtime_ms' => $runtimeMs,
                'exit_code' => $exitCode,
                'status' => 'failed',
                'output' => $this->capturer->capture($task),
                'exception' => $exceptionPayload,
            ]);

            $this->maybeFireDomainEvent($taskKey, $runId, $event);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskFailed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        } finally {
            if (is_string($taskKey)) {
                ScheduleContext::pop($taskKey);
            }
        }
    }

    private function maybeFireDomainEvent(string $taskKey, string $runId, ScheduledTaskFailed $event): void
    {
        if (! Config::bool('scheduler.alerts.enabled', false)) {
            return;
        }

        if (! $this->cooldown->acquire('failed', $taskKey)) {
            return;
        }

        EventDispatcher::dispatch(new ScheduledTaskFailedDomainEvent(
            $taskKey,
            $runId,
            $event->task,
            $event->exception,
        ));
    }

    private function truncate(string $value, int $cap): string
    {
        return strlen($value) <= $cap ? $value : substr($value, 0, $cap) . '…';
    }
}
