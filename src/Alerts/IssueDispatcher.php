<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Alerts\Detectors\FailureRateDetector;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Events\BacklogGrowing;
use SanderMuller\QueueInsights\Events\JobClassFailureRateExceeded;
use SanderMuller\QueueInsights\Events\JobClassP95Exceeded;
use SanderMuller\QueueInsights\Events\JobFailedAlert;
use SanderMuller\QueueInsights\Events\OldestPendingAging;
use SanderMuller\QueueInsights\Events\QueueDepthExceeded;
use SanderMuller\QueueInsights\Events\QueueStalled;
use SanderMuller\QueueInsights\Events\ScheduledTaskFailed as DomainScheduledTaskFailed;
use SanderMuller\QueueInsights\Events\ScheduledTaskHung as DomainScheduledTaskHung;
use SanderMuller\QueueInsights\Events\ScheduledTaskMissed as DomainScheduledTaskMissed;
use SanderMuller\QueueInsights\Events\SnapshotErrored;
use SanderMuller\QueueInsights\Events\StuckInFlight;
use SanderMuller\QueueInsights\Scheduler\CommandLabel;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\SilencedJobs;
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

    /** Snapshot-command path — uses the pre-known depth value. */
    public function dispatchForSnapshot(string $connection, string $canonicalQueue, int $depth): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        foreach ($this->detector->detectForSnapshot($connection, $canonicalQueue, $depth) as $issue) {
            $this->handle($issue);
        }
    }

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
     * Scheduler entry — task failed. Builds a task-scoped Issue and routes
     * through the standard cooldown → typed-event → notify pipeline.
     * Gates internally on `scheduler.alerts.enabled` so callers don't need
     * to repeat the check.
     */
    /**
     * @param  array<string, mixed>|null  $failureContext  sanitized Context + environment snapshot
     */
    public function dispatchScheduledTaskFailed(string $taskKey, string $runId, Event $task, ?Throwable $exception, ?array $failureContext = null): void
    {
        if (! Config::bool('scheduler.alerts.enabled', false)) {
            return;
        }

        $resolved = ScheduledTaskLabel::for($task, $taskKey);

        $context = $this->mergeTaskSummary([
            'task_key' => $taskKey,
            'run_id' => $runId,
            'exception_class' => $exception instanceof Throwable ? $exception::class : null,
            'exception_message' => $exception?->getMessage() ?? '',
        ], $resolved['summary']);

        $issue = new Issue(
            rule: 'scheduled_task_failed',
            severity: AlertSeverity::Critical,
            connection: '',
            queue: '',
            jobClass: null,
            title: "Scheduled task failed: {$resolved['label']}",
            description: $exception instanceof Throwable
                ? sprintf('%s — %s', $exception::class, $exception->getMessage())
                : 'Task exited with non-zero status.',
            context: $context,
            detectedAt: Date::now()->getTimestamp(),
            taskKey: $taskKey,
        );

        $this->handleScheduled($issue, fn (): DomainScheduledTaskFailed => new DomainScheduledTaskFailed(
            $taskKey,
            $runId,
            $task,
            $exception,
            $failureContext,
        ));
    }

    public function dispatchScheduledTaskHung(string $taskKey, string $runId, ?Event $task, int $startedAtMs, int $elapsedSeconds): void
    {
        if (! Config::bool('scheduler.alerts.enabled', false)) {
            return;
        }

        $resolved = ScheduledTaskLabel::for($task, $taskKey);

        $context = $this->mergeTaskSummary([
            'task_key' => $taskKey,
            'run_id' => $runId,
            'started_at_ms' => $startedAtMs,
            'elapsed_seconds' => $elapsedSeconds,
        ], $resolved['summary']);

        $issue = new Issue(
            rule: 'scheduled_task_hung',
            severity: AlertSeverity::Warning,
            connection: '',
            queue: '',
            jobClass: null,
            title: "Scheduled task hung: {$resolved['label']}",
            description: sprintf('Task has been running for %ds without finishing.', $elapsedSeconds),
            context: $context,
            detectedAt: Date::now()->getTimestamp(),
            taskKey: $taskKey,
        );

        $this->handleScheduled($issue, fn (): DomainScheduledTaskHung => new DomainScheduledTaskHung(
            $taskKey,
            $runId,
            $task,
            $startedAtMs,
            $elapsedSeconds,
        ));
    }

    public function dispatchScheduledTaskMissed(string $taskKey, Event $task, int $expectedAtMs): void
    {
        if (! Config::bool('scheduler.alerts.enabled', false)) {
            return;
        }

        $resolved = ScheduledTaskLabel::for($task, $taskKey);

        $context = $this->mergeTaskSummary([
            'task_key' => $taskKey,
            'expected_at_ms' => $expectedAtMs,
        ], $resolved['summary']);

        $issue = new Issue(
            rule: 'scheduled_task_missed',
            severity: AlertSeverity::Warning,
            connection: '',
            queue: '',
            jobClass: null,
            title: "Scheduled task missed: {$resolved['label']}",
            description: 'No Starting event observed within the drift window.',
            context: $context,
            detectedAt: Date::now()->getTimestamp(),
            taskKey: $taskKey,
        );

        $this->handleScheduled($issue, fn (): DomainScheduledTaskMissed => new DomainScheduledTaskMissed(
            $taskKey,
            $task,
            $expectedAtMs,
        ));
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
        if ($this->isSilenced($issue)) {
            return;
        }

        if (! $this->cooldown->acquire($issue)) {
            return;
        }

        $this->safelyFireEvent($issue);
        $this->notify($issue);
    }

    /**
     * Shared silencing predicate. Silencing is failure-noise scoped, not perf —
     * `slow_p95` also sets `jobClass` but stays unfiltered (silenced-jobs spec
     * §1). Evaluated BEFORE `cooldown::acquire` — guarding later would burn the
     * cooldown while notify() still fired. Covers the poll-driven `failure_rate`
     * rule and the event-driven `job_failed` rule.
     */
    private function isSilenced(Issue $issue): bool
    {
        $silenceableRules = [FailureRateDetector::RULE, 'job_failed'];

        return in_array($issue->rule, $silenceableRules, true)
            && $issue->jobClass !== null && $issue->jobClass !== ''
            && $this->container->make(SilencedJobs::class)->isSilenced($issue->jobClass);
    }

    /**
     * Queue-domain event entry — job failed. Builds a class-scoped Issue and
     * routes through the standard cooldown → typed-event → notify pipeline.
     * Gates internally on `alerts.enabled` + `alerts.rules.job_failed.enabled`
     * so the listener can call unconditionally.
     *
     * @param  array<string, mixed>|null  $failureContext  sanitized Context + environment snapshot
     */
    public function dispatchJobFailed(string $jobClass, string $connection, string $queue, ?string $uuid, ?Throwable $exception, ?array $failureContext = null): void
    {
        if (! Config::bool('alerts.enabled', false) || ! Config::bool('alerts.rules.job_failed.enabled', false)) {
            return;
        }

        $severity = AlertSeverity::tryFrom(
            Config::string('alerts.rules.job_failed.severity', AlertSeverity::Warning->value)
        ) ?? AlertSeverity::Warning;

        $issue = new Issue(
            rule: 'job_failed',
            severity: $severity,
            connection: $connection,
            queue: $queue,
            jobClass: $jobClass,
            title: "Job failed: {$jobClass}",
            description: $exception instanceof Throwable
                ? sprintf('%s — %s', $exception::class, $exception->getMessage())
                : 'Job failed.',
            context: [
                'uuid' => $uuid,
                'exception_class' => $exception instanceof Throwable ? $exception::class : null,
                'exception_message' => $exception?->getMessage() ?? '',
            ],
            detectedAt: Date::now()->getTimestamp(),
        );

        $this->handleQueueEvent($issue, fn (): JobFailedAlert => new JobFailedAlert(
            $jobClass,
            $connection,
            $queue,
            $uuid,
            $exception,
            $severity->value,
            $failureContext,
        ));
    }

    /**
     * Queue-domain twin of `handleScheduled`: cooldown + prebuilt typed event +
     * notify, but it also runs the silencing predicate and gates the package's
     * synchronous channels on the per-rule `notify` flag. The typed event carries
     * a live `Throwable` that can't round-trip the `Issue::context` array, so the
     * caller builds it eagerly as a closure (lazy so cooldown-suppressed calls
     * don't pay the construction cost).
     *
     * The shared poll-driven `handle()` is deliberately left untouched — this
     * keeps the hot path's blast radius bounded.
     *
     * @param Closure():object $eventBuilder
     */
    private function handleQueueEvent(Issue $issue, Closure $eventBuilder): void
    {
        if ($this->isSilenced($issue)) {
            return;
        }

        if (! $this->cooldown->acquire($issue)) {
            return;
        }

        try {
            event($eventBuilder());
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: job_failed alert event listener threw', [
                'rule' => $issue->rule,
                'job_class' => $issue->jobClass,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        if (Config::bool('alerts.rules.job_failed.notify', true)) {
            $this->notify($issue);
        }
    }

    /**
     * Scheduler-domain handle: cooldown + prebuilt typed event + notify. The
     * scheduler events carry framework `Event` instances that don't fit the
     * `context: array<string, mixed>` shape, so the typed event is built by
     * the caller and passed in as a closure (lazy so cooldown-suppressed
     * calls don't pay the construction cost).
     *
     * `notify()` is gated on the package-wide `alerts.enabled` master switch
     * so a host that previously ran with `scheduler.alerts.enabled=true` for
     * typed events while keeping `alerts.enabled=false` does NOT suddenly
     * start emitting log/slack/mail notifications post-upgrade. Typed events
     * keep firing under the scheduler-side flag alone — that preserves the
     * pre-7b behaviour for hosts that wired their own listeners.
     *
     * @param Closure():object $eventBuilder
     */
    private function handleScheduled(Issue $issue, Closure $eventBuilder): void
    {
        if (! $this->cooldown->acquire($issue)) {
            return;
        }

        try {
            event($eventBuilder());
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: scheduled alert event listener threw', [
                'rule' => $issue->rule,
                'task_key' => $issue->taskKey,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        if (Config::bool('alerts.enabled', false)) {
            $this->notify($issue);
        }
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
     * Mixed-tolerant int extractor — PHPStan strict-rules disallows `(int) $mixed`.
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

    /**
     * Fold the resolved task summary into the alert context. Only a curated
     * subset surfaces in mail/Slack — booleans and the long mutex name stay
     * out of the operator-facing payload.
     *
     * @param  array<string, mixed>  $context
     * @param  ?array{description: ?string, command: string, expression: string, timezone: ?string, type: 'command'|'closure'|'exec', mutexName: string, ...}  $summary
     * @return array<string, mixed>
     */
    private function mergeTaskSummary(array $context, ?array $summary): array
    {
        if ($summary === null) {
            return $context;
        }

        $description = AlertText::sanitise($summary['description']);
        if ($description !== '') {
            $context['task_description'] = $description;
        }

        $command = AlertText::sanitise($summary['command']);
        if ($command !== '') {
            // `CommandLabel::short` strips the absolute PHP-binary prefix so
            // `'/Users/.../Herd/bin/php' 'artisan' 'reports:export'` reads as
            // `php artisan reports:export` — the verbose form is still on
            // the task hash in Redis for the drilldown modal.
            $context['task_command'] = CommandLabel::short($command);
        }

        $expression = AlertText::sanitise($summary['expression']);
        if ($expression !== '') {
            $context['task_expression'] = $expression;
        }

        $timezone = AlertText::sanitise($summary['timezone']);
        if ($timezone !== '') {
            $context['task_timezone'] = $timezone;
        }

        $context['task_type'] = $summary['type'];

        return $context;
    }
}
