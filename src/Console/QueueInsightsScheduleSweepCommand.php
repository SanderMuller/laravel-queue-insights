<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Scheduler\HostId;
use SanderMuller\QueueInsights\Scheduler\HungTaskReconciler;
use SanderMuller\QueueInsights\Scheduler\MissedRunReconciler;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

/**
 * Phase 3 sweeper. Self-registers under `Schedule::command()->everyMinute()
 * ->onOneServer()->withoutOverlapping()` from the service provider when
 * `scheduler.sweeper.enabled=true`.
 *
 * Two reconcilers run sequentially:
 *   1. Missed-run — emits synthetic `missed` runs for expected fires we
 *      never saw a `Starting` event for.
 *   2. Hung-task — flips `status=hung` on running runs whose
 *      `expected_finish_at_ms` is in the past.
 *
 * Optionally pings `scheduler.heartbeat.url` so an external SaaS alerts
 * if `schedule:run` itself dies. Heartbeat failures are swallowed —
 * the sweeper's own role is observability, not network reliability.
 */
final class QueueInsightsScheduleSweepCommand extends Command
{
    protected $signature = 'queue-insights:schedule:sweep';

    protected $description = 'Reconcile expected vs actual scheduled-task runs (missed + hung detection).';

    public function __construct(
        private readonly MissedRunReconciler $missed,
        private readonly HungTaskReconciler $hung,
    ) {
        parent::__construct();
    }

    public function handle(Schedule $schedule): int
    {
        if (! Config::bool('scheduler.enabled', false)) {
            return self::SUCCESS;
        }

        if (! Config::bool('scheduler.sweeper.enabled', true)) {
            return self::SUCCESS;
        }

        $missedCount = $this->missed->reconcile($schedule);
        $hungCount = $this->hung->reconcile($schedule);

        $this->postHeartbeat(count($schedule->events()));

        $this->info(sprintf(
            'Schedule sweep: %d missed, %d hung.',
            $missedCount,
            $hungCount,
        ));

        return self::SUCCESS;
    }

    private function postHeartbeat(int $tasksSwept): void
    {
        if (! Config::bool('scheduler.heartbeat.enabled', false)) {
            return;
        }

        $url = Config::string('scheduler.heartbeat.url', '');
        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'host_id' => HostId::resolve(),
                'timestamp' => Date::now()
                    ->getTimestamp(),
                'tasks_swept' => $tasksSwept,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: scheduler heartbeat post failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
