<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Events\BacklogGrowing;
use SanderMuller\QueueInsights\Events\JobClassFailureRateExceeded;
use SanderMuller\QueueInsights\Events\JobClassP95Exceeded;
use SanderMuller\QueueInsights\Events\OldestPendingAging;
use SanderMuller\QueueInsights\Events\QueueDepthExceeded;
use SanderMuller\QueueInsights\Events\QueueStalled;
use SanderMuller\QueueInsights\Events\SnapshotErrored;
use SanderMuller\QueueInsights\Events\StuckInFlight;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

/**
 * Cooldown-gated dispatch engine. Wraps the pure IssueDetector with the side-
 * effect path: cooldown gate -> typed event -> Notification dispatch.
 *
 * Notifications are routed via Laravel's notification system to the bound
 * `QueueInsightsNotifiable` (default reads config, host apps can swap by
 * binding their own implementation in the container). This is the same
 * pattern used by spatie/laravel-backup, spatie/laravel-uptime-monitor,
 * and laravel/horizon — so adding Discord/Teams/PagerDuty/Telegram is
 * "install the matching `laravel-notification-channels/*` package + override
 * `QueueAlertNotification::via()` and add `routeNotificationForX()` on the
 * notifiable" with zero changes to this class.
 */
final readonly class IssueDispatcher
{
    public function __construct(
        private IssueDetector $detector,
        private Cooldown $cooldown,
        private Container $container,
    ) {}

    /**
     * Dispatch any active issues for one (connection, queue) pair using a
     * pre-known depth value (snapshot-command path).
     */
    public function dispatchForSnapshot(string $connection, string $canonicalQueue, int $depth): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        foreach ($this->detector->detectForSnapshot($connection, $canonicalQueue, $depth) as $issue) {
            $this->handle($issue);
        }
    }

    /**
     * Run detectAll() and dispatch each issue that clears the cooldown gate.
     */
    public function run(): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        foreach ($this->detector->detectAll() as $issue) {
            $this->handle($issue);
        }
    }

    /**
     * Class-scoped sweep — fires once per snapshot tick after the per-queue
     * loop. Iterates `qi:classes` (jobs that have actually been seen) rather
     * than `Config::array('snapshots')`, since class-scoped rules track
     * runtime behaviour, not configured queues.
     */
    public function dispatchClassScoped(): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        foreach ($this->detector->jobClasses() as $class) {
            foreach ($this->detector->detectClassScoped($class) as $issue) {
                $this->handle($issue);
            }
        }
    }

    /**
     * Catch-branch path from the snapshot command — fires snapshot_errored for
     * a single (connection, queue) right after the error key was written.
     */
    public function dispatchSnapshotError(string $connection, string $canonicalQueue): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        $issue = $this->detector->detectSnapshotError($connection, $canonicalQueue);
        if (! $issue instanceof Issue) {
            return;
        }

        $this->handle($issue);
    }

    private function handle(Issue $issue): void
    {
        if (! $this->cooldown->acquire($issue)) {
            return;
        }

        $this->safelyFireEvent($issue);
        $this->notify($issue);
    }

    /**
     * Wrap event dispatch so a misbehaving host listener can't abort the
     * notification path after the cooldown has already been consumed. A lost
     * event is the lesser evil compared to a silent alert.
     */
    private function safelyFireEvent(Issue $issue): void
    {
        try {
            $this->fireEvent($issue);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: alert event listener threw', [
                'rule' => $issue->rule,
                'severity' => $issue->severity->value,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch the QueueAlertNotification per-channel so one failing channel
     * (slack 5xx, mail SMTP error, custom channel bug) does not block the
     * remaining channels — Laravel's `Notifiable::notify()` walks `via()`
     * with no per-channel try/catch, so a single throw bypasses siblings.
     * The cooldown was already acquired, so a half-delivered alert is the
     * worst-case: the next tick's cooldown gate suppresses re-fires.
     */
    private function notify(Issue $issue): void
    {
        try {
            $notifiable = $this->container->make(QueueInsightsNotifiable::class);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: alert notifiable resolution failed', [
                'rule' => $issue->rule,
                'severity' => $issue->severity->value,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return;
        }

        $notification = new QueueAlertNotification($issue);
        $channels = $notification->via($notifiable);

        foreach ($channels as $channel) {
            try {
                $notifiable->notifyNow($notification, [$channel]);
            } catch (Throwable $throwable) {
                Log::warning('queue-insights: alert channel send failed', [
                    'rule' => $issue->rule,
                    'severity' => $issue->severity->value,
                    'channel' => $channel,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }
    }

    private function fireEvent(Issue $issue): void
    {
        $ctx = $issue->context;
        $event = match ($issue->rule) {
            'depth' => new QueueDepthExceeded(
                connection: $issue->connection,
                queue: $issue->queue,
                depth: $this->ctxInt($ctx, 'depth'),
                threshold: $this->ctxInt($ctx, 'threshold'),
                severity: $issue->severity->value,
            ),
            'stalled' => new QueueStalled(
                connection: $issue->connection,
                queue: $issue->queue,
                depth: $this->ctxInt($ctx, 'depth'),
                idleSeconds: $this->ctxInt($ctx, 'idle_seconds'),
                severity: $issue->severity->value,
            ),
            'oldest_pending' => new OldestPendingAging(
                connection: $issue->connection,
                queue: $issue->queue,
                ageSeconds: $this->ctxInt($ctx, 'age_seconds'),
                thresholdSeconds: $this->ctxInt($ctx, 'threshold_seconds'),
                oldestUuid: $this->ctxString($ctx, 'oldest_uuid'),
                oldestClass: $this->ctxString($ctx, 'oldest_class'),
                severity: $issue->severity->value,
            ),
            'stuck_inflight' => new StuckInFlight(
                connection: $issue->connection,
                queue: $issue->queue,
                ageSeconds: $this->ctxInt($ctx, 'age_seconds'),
                thresholdSeconds: $this->ctxInt($ctx, 'threshold_seconds'),
                oldestUuid: $this->ctxString($ctx, 'oldest_uuid'),
                oldestClass: $this->ctxString($ctx, 'oldest_class'),
                severity: $issue->severity->value,
            ),
            'backlog_growing' => new BacklogGrowing(
                connection: $issue->connection,
                queue: $issue->queue,
                slopePerMinute: $this->ctxFloat($ctx, 'slope_per_minute'),
                minSlopePerMinute: $this->ctxFloat($ctx, 'min_slope_per_minute'),
                samples: $this->ctxInt($ctx, 'samples'),
                latestDepth: $this->ctxInt($ctx, 'latest_depth'),
                windowSeconds: $this->ctxInt($ctx, 'window_seconds'),
                severity: $issue->severity->value,
            ),
            'snapshot_errored' => new SnapshotErrored(
                connection: $issue->connection,
                queue: $issue->queue,
                errorMessage: $this->ctxString($ctx, 'error_message'),
                severity: $issue->severity->value,
            ),
            'failure_rate' => new JobClassFailureRateExceeded(
                jobClass: $issue->jobClass ?? '',
                failed: $this->ctxInt($ctx, 'failed'),
                processed: $this->ctxInt($ctx, 'processed'),
                total: $this->ctxInt($ctx, 'total'),
                ratio: $this->ctxFloat($ctx, 'ratio'),
                ratioThreshold: $this->ctxFloat($ctx, 'ratio_threshold'),
                minJobs: $this->ctxInt($ctx, 'min_jobs'),
                bucket: $this->ctxString($ctx, 'bucket'),
                severity: $issue->severity->value,
            ),
            'slow_p95' => new JobClassP95Exceeded(
                jobClass: $issue->jobClass ?? '',
                p95Ms: $this->ctxInt($ctx, 'p95_ms'),
                thresholdMs: $this->ctxInt($ctx, 'threshold_ms'),
                sampleCount: $this->ctxInt($ctx, 'sample_count'),
                severity: $issue->severity->value,
            ),
            default => null,
        };

        if ($event !== null) {
            event($event);
        }
    }

    /**
     * Mixed-tolerant int extractor for `Issue::$context` reads. PHPStan's
     * strict-rules pass disallows `(int) $mixed` casts; this narrow helper
     * keeps the match expression readable while satisfying the type checker.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function ctxInt(array $ctx, string $key): int
    {
        $value = $ctx[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function ctxFloat(array $ctx, string $key): float
    {
        $value = $ctx[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function ctxString(array $ctx, string $key): string
    {
        $value = $ctx[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
