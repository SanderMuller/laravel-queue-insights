<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use Throwable;

/**
 * Walks every `qi:sched:running:*` pointer hash and flags any
 * whose `expected_finish_at_ms` is in the past as `status=hung`.
 * Fires `ScheduledTaskHung` (cooldown-gated) per first detection.
 *
 * Late-arriving `Finished` / `Failed` events for the same run will
 * flip the status to success/failed and stamp `recovered_from_hung=1`
 * — handled in `RunStore::recordFinish`.
 */
final readonly class HungTaskReconciler
{
    public function __construct(
        private RunStore $store,
        private ScheduleReader $reader,
        private IssueDispatcher $dispatcher,
    ) {}

    /**
     * @return int  count of hung runs flagged this sweep
     */
    public function reconcile(Schedule $schedule, ?int $nowMs = null): int
    {
        $nowMs ??= Date::now()->getTimestampMs();

        $running = $this->reader->runningTasks();
        if ($running === []) {
            return 0;
        }

        // Map task → Event (so the dispatched event can carry the task).
        $eventByKey = [];
        foreach ($schedule->events() as $event) {
            $eventByKey[TaskKey::for($event)] = $event;
        }

        $count = 0;
        foreach ($running as $taskKey => $state) {
            try {
                if ($state['expected_finish_at_ms'] >= $nowMs) {
                    continue;
                }

                $this->store->recordHung($taskKey, $state['run_id']);

                $event = $eventByKey[$taskKey] ?? null;
                assert($event === null || $event instanceof Event);
                $elapsedSeconds = (int) max(0, ($nowMs - $state['started_at_ms']) / 1000);
                $this->dispatcher->dispatchScheduledTaskHung(
                    $taskKey,
                    $state['run_id'],
                    $event,
                    $state['started_at_ms'],
                    $elapsedSeconds,
                );

                ++$count;
            } catch (Throwable $throwable) {
                Log::warning('queue-insights: HungTaskReconciler::reconcile failed for task', [
                    'task_key' => $taskKey,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
