<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Scheduler\OutputCapturer;
use SanderMuller\QueueInsights\Scheduler\RunStore;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Scheduler\TaskKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\FailureContextCollector;
use Throwable;

final readonly class RecordScheduledTaskFailed
{
    public function __construct(
        private RunStore $store,
        private OutputCapturer $capturer,
        private IssueDispatcher $dispatcher,
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

            // Inner (deepest previous) exception as discrete fields. Unlike a
            // queued job — whose `failed_jobs.exception` keeps the full nested
            // chain — a task run stores only the 4000-char trace tail, which
            // can truncate the root cause out. Capturing it explicitly keeps
            // it visible.
            $previous = $this->deepestPrevious($exception);
            if ($previous instanceof Throwable) {
                $exceptionPayload['inner_class'] = $previous::class;
                $exceptionPayload['inner_message'] = $this->truncate($previous->getMessage(), 2000);
            }

            $failureContext = Config::bool('failure_context.enabled', true)
                ? (new FailureContextCollector())->collect()
                : null;

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
                    if ($failureContext !== null) {
                        $this->store->stampFailureContext($taskKey, $existingRunId, $failureContext);
                    }

                    $this->maybeFireDomainEvent($taskKey, $existingRunId, $event, $failureContext);

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

            if ($failureContext !== null) {
                $this->store->stampFailureContext($taskKey, $runId, $failureContext);
            }

            $this->maybeFireDomainEvent($taskKey, $runId, $event, $failureContext);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskFailed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        } finally {
            if (is_string($taskKey)) {
                ScheduleContext::pop($taskKey);
            }

            // Drop the initiator origin Starting set so a later task in
            // the same `schedule:run` worker doesn't inherit it.
            $this->forgetInitiatorOrigin();
        }
    }

    private function forgetInitiatorOrigin(): void
    {
        try {
            if (Config::bool('initiator.enabled', true) && Config::bool('initiator.capture_origin', true)) {
                Context::forgetHidden(Config::string('initiator.context_key', 'qi_origin'));
            }
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordScheduledTaskFailed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $failureContext
     */
    private function maybeFireDomainEvent(string $taskKey, string $runId, ScheduledTaskFailed $event, ?array $failureContext): void
    {
        $this->dispatcher->dispatchScheduledTaskFailed(
            $taskKey,
            $runId,
            $event->task,
            $event->exception,
            $failureContext,
        );
    }

    private function truncate(string $value, int $cap): string
    {
        return strlen($value) <= $cap ? $value : substr($value, 0, $cap) . '…';
    }

    /**
     * Walk the `getPrevious()` chain to the deepest wrapped exception — the
     * usual root cause. Null when the exception has no previous.
     */
    private function deepestPrevious(Throwable $exception): ?Throwable
    {
        $previous = $exception->getPrevious();
        if (! $previous instanceof Throwable) {
            return null;
        }

        while (($next = $previous->getPrevious()) instanceof Throwable) {
            $previous = $next;
        }

        return $previous;
    }
}
