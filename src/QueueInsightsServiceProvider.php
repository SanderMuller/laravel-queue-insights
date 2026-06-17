<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Override;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Alerts\Cooldown;
use SanderMuller\QueueInsights\Alerts\Detectors\DepthDetector;
use SanderMuller\QueueInsights\Alerts\IssueDetector;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueInsightsNotifiable;
use SanderMuller\QueueInsights\Alerts\SnapshotWatchdog;
use SanderMuller\QueueInsights\Console\DefaultWorkerOutputStreams;
use SanderMuller\QueueInsights\Console\DefaultWorkerProcessFactory;
use SanderMuller\QueueInsights\Console\QueueInsightsMigrateAliasesCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsPrometheusPushCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsPurgePendingCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsScheduleListCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsScheduleSweepCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsSnapshotCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsWorkCommand;
use SanderMuller\QueueInsights\Console\WorkerOutputStreams;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Dashboard\RetryAction;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Http\Livewire\AlertRulesPanel;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Http\Livewire\ScheduleInsightsPanel;
use SanderMuller\QueueInsights\Http\Middleware\SetInitiatorOrigin;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Listeners\RecordScheduledBackgroundTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFailed;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskFinished;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskSkipped;
use SanderMuller\QueueInsights\Listeners\RecordScheduledTaskStarting;
use SanderMuller\QueueInsights\Listeners\SetInitiatorOriginFromCommand;
use SanderMuller\QueueInsights\Prometheus\ClassFilter as PrometheusClassFilter;
use SanderMuller\QueueInsights\Prometheus\Collector;
use SanderMuller\QueueInsights\Prometheus\Collectors\AlertActiveCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\DelayedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\DurationAggregateCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\ExporterSelfCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\InflightCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\JobsFailedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\JobsProcessedCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\OldestInflightAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\OldestPendingAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\PendingCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\QueueDepthCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\HungTotalCollector as SchedulerHungTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\InFlightCollector as SchedulerInFlightCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\LastRunTimestampCollector as SchedulerLastRunTimestampCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\MissedTotalCollector as SchedulerMissedTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\RunsTotalCollector as SchedulerRunsTotalCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\RuntimeSumCollector as SchedulerRuntimeSumCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\SnapshotAgeCollector as SchedulerSnapshotAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\Scheduler\SweeperAgeCollector as SchedulerSweeperAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAliveCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotErrorsCollector;
use SanderMuller\QueueInsights\Prometheus\PrometheusAuthMiddleware;
use SanderMuller\QueueInsights\Prometheus\Registry as PrometheusRegistry;
use SanderMuller\QueueInsights\Prometheus\Renderer as PrometheusRenderer;
use SanderMuller\QueueInsights\Prometheus\Scheduler\CountersReader as SchedulerCountersReader;
use SanderMuller\QueueInsights\Prometheus\Scheduler\TaskFilter as SchedulerTaskFilter;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use SanderMuller\QueueInsights\Support\Sanitizers\KeyRedactingSanitizer;
use SanderMuller\QueueInsights\Support\Sanitizers\MetadataOnlySanitizer;
use SanderMuller\QueueInsights\Support\SilencedJobs;

final class QueueInsightsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/queue-insights.php',
            'queue-insights',
        );

        $this->app->singleton(QueueInsights::class);
        $this->app->singleton(QueueSnapshotDriverFactory::class);
        $this->app->bind(WorkerProcessFactory::class, DefaultWorkerProcessFactory::class);
        $this->app->bind(WorkerOutputStreams::class, DefaultWorkerOutputStreams::class);
        // Stateless retry orchestrator for the failed-jobs dashboard. Bound
        // (not singleton) — cheap to construct fresh per Livewire request,
        // matches the Octane-correct default the codebase favours for
        // stateless collaborators.
        $this->app->bind(RetryAction::class);
        $this->app->singleton(DepthDetector::class);
        $this->app->singleton(Cooldown::class);
        $this->app->singleton(IssueDetector::class);
        $this->app->singleton(IssueDispatcher::class);
        $this->app->singleton(SnapshotWatchdog::class);
        // ActiveIssuesProvider keeps a per-request memoise on the instance —
        // bind (not singleton) so each Livewire render gets a fresh
        // instance and the memoise doesn't leak across requests under
        // Octane / persistent processes. Cross-request bound is still the
        // 5s Redis cache (`alert:cache:active-issues`).
        $this->app->bind(ActiveIssuesProvider::class);

        // Notifiable is bound (not singleton) so each notify() call gets a
        // clean instance — Laravel's Notifiable trait carries per-instance
        // pending state we don't want to leak across alerts.
        $this->app->bind(QueueInsightsNotifiable::class);

        // `scoped()` resets between requests so a config change to
        // `queue-insights.silenced` picks up on the next render under
        // Octane without needing a worker restart.
        $this->app->scoped(SilencedJobs::class);

        // Prometheus collectors carry no per-request state, but the
        // Registry MUST be `bind()` (not `singleton`) so the per-flavour
        // render memoise doesn't leak across requests under Octane /
        // persistent processes — mirrors the ActiveIssuesProvider pattern
        // above. The Redis cache layer is the cross-request bound.
        $this->app->bind(PrometheusRenderer::class);
        // ExporterSelfCollector carries the per-cycle duration sample
        // between Registry::collect and its own collect() call — must
        // be the SAME instance for a given Registry instance. `scoped`
        // resets it per-request alongside the Registry binding.
        $this->app->scoped(ExporterSelfCollector::class);
        // ClassFilter memoises `classesFor(connection)` per-(mode,
        // connection) so the three class-scoped collectors share one
        // ZRANGE per scrape instead of three. `scoped` so the memoise
        // dies with the request.
        $this->app->scoped(PrometheusClassFilter::class);
        // Same memoise pattern as ClassFilter — the eight scheduler
        // collectors share one LRANGE on `sched:tasks:order` per scrape.
        $this->app->scoped(SchedulerTaskFilter::class);
        // Per-task counters-hash reader: five scheduler collectors all
        // read fields from `sched:counters:{task}`. The reader does one
        // HGETALL per task per scrape, memoised on the instance, so the
        // round-trip count collapses from 5×N to N. `scoped` so the
        // memoise dies with the request.
        $this->app->scoped(SchedulerCountersReader::class);
        $this->app->bind(PrometheusRegistry::class, function (Application $app): PrometheusRegistry {
            $collectorClasses = [
                QueueDepthCollector::class,
                InflightCollector::class,
                PendingCollector::class,
                DelayedCollector::class,
                OldestPendingAgeCollector::class,
                OldestInflightAgeCollector::class,
                SnapshotAliveCollector::class,
                SnapshotAgeCollector::class,
                JobsProcessedCollector::class,
                JobsFailedCollector::class,
                DurationAggregateCollector::class,
                SnapshotErrorsCollector::class,
                AlertActiveCollector::class,
                // Scheduler families — gated additionally on
                // `scheduler.enabled` inside each collector's
                // `isEnabled()`. Registry's try/catch protects siblings
                // if any individual collector throws.
                SchedulerRunsTotalCollector::class,
                SchedulerRuntimeSumCollector::class,
                SchedulerLastRunTimestampCollector::class,
                SchedulerHungTotalCollector::class,
                SchedulerMissedTotalCollector::class,
                SchedulerInFlightCollector::class,
                SchedulerSnapshotAgeCollector::class,
                SchedulerSweeperAgeCollector::class,
            ];

            $collectors = [];
            foreach ($collectorClasses as $class) {
                $resolved = $app->make($class);
                assert($resolved instanceof Collector);
                $collectors[] = $resolved;
            }

            $renderer = $app->make(PrometheusRenderer::class);
            assert($renderer instanceof PrometheusRenderer);
            $self = $app->make(ExporterSelfCollector::class);
            assert($self instanceof ExporterSelfCollector);

            return new PrometheusRegistry($collectors, $renderer, $self);
        });

        $this->app->bind(PayloadSanitizer::class, fn (): PayloadSanitizer => match (Config::enum('capture.payloads', CaptureMode::class, CaptureMode::Off)) {
            CaptureMode::Metadata => new MetadataOnlySanitizer(),
            CaptureMode::Full => new KeyRedactingSanitizer(
                array_values(array_filter(
                    Config::array('capture.redact_keys'),
                    is_string(...),
                )),
                Config::int('capture.max_field_bytes', 2048),
                Config::int('capture.max_payload_bytes', 16384),
            ),
            // The `Off` mode never reaches here in production — listeners
            // gate on `CaptureMode::Off->writesPayloadFields() === false`
            // before resolving the sanitizer. Bind a metadata-only
            // fallback so a misordered host that resolves it anyway gets
            // safe behaviour rather than a TypeError.
            CaptureMode::Off => new MetadataOnlySanitizer(),
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/queue-insights.php' => config_path('queue-insights.php'),
            ], ['queue-insights', 'queue-insights-config']);

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/queue-insights'),
            ], ['queue-insights', 'queue-insights-views']);

            $this->commands([
                QueueInsightsSnapshotCommand::class,
                QueueInsightsWorkCommand::class,
                QueueInsightsPrometheusPushCommand::class,
                QueueInsightsScheduleListCommand::class,
                QueueInsightsScheduleSweepCommand::class,
                QueueInsightsPurgePendingCommand::class,
                QueueInsightsMigrateAliasesCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'queue-insights');

        // Fetch the merged queue-insights config once so the gate + every
        // validator + the warn pass each work off a single repository read,
        // not 8+ separate `Config::*` facade hops.
        $cfg = config('queue-insights');
        $cfg = is_array($cfg) ? $cfg : [];

        if (($cfg['enabled'] ?? true) === false) {
            return;
        }

        $section = static function (array $cfg, string $key): array {
            $value = $cfg[$key] ?? null;

            return is_array($value) ? $value : [];
        };

        // Aliases validated BEFORE snapshots so the snapshot collision check
        // can rely on a well-formed alias map for canonicalisation.
        ConfigValidator::validateConnectionAliases($section($cfg, 'connection_aliases'));
        ConfigValidator::validateSnapshots($section($cfg, 'snapshots'));
        ConfigValidator::validatePending($section($cfg, 'pending'));
        ConfigValidator::validateBatches($section($cfg, 'batches'));

        $alerts = $section($cfg, 'alerts');
        // The alerts validator pulls in the 424-LOC AlertsConfigValidator
        // class. When alerts are disabled (the default) the detector chain
        // never runs, so misconfig in `alerts.*` cannot surface — skip the
        // boot-time validation and the autoload it would trigger.
        if (($alerts['enabled'] ?? false) === true) {
            ConfigValidator::validateAlerts($alerts);
        }

        ConfigValidator::validateCapture($section($cfg, 'capture'));
        ConfigValidator::validateChainLineage($section($cfg, 'chain_lineage'));
        ConfigValidator::validateInitiator($section($cfg, 'initiator'));
        ConfigValidator::validateFailureContext($section($cfg, 'failure_context'));
        ConfigValidator::validateSentry($section($cfg, 'sentry'));
        ConfigValidator::validateWork($section($cfg, 'work'));
        ConfigValidator::validateRetention($section($cfg, 'retention'));
        ConfigValidator::validatePrometheus($section($cfg, 'prometheus'));
        ConfigValidator::validateDashboard($section($cfg, 'dashboard'));
        ConfigValidator::validateScheduler($section($cfg, 'scheduler'));
        ConfigValidator::validateHorizon($section($cfg, 'horizon'));

        // Silenced fails loud on a non-array shape rather than coercing
        // to `[]` like the other section validators — a `silenced =>
        // 'App\\Jobs\\Foo'` typo (string instead of list) would otherwise
        // become "feature off" with no error, which is a real foot-gun
        // for hosts trying to mute a noisy class.
        $silenced = $cfg['silenced'] ?? [];
        if (! is_array($silenced)) {
            throw new QueueInsightsConfigException(
                'queue-insights.silenced must be a list of class-name strings (got ' . get_debug_type($silenced) . ').'
            );
        }

        ConfigValidator::validateSilenced($silenced);

        // silenced_patterns mirrors the same fail-loud-on-non-array stance
        // as silenced. Same rationale: a `silenced_patterns => 'App\\*'`
        // typo (string instead of list) silently becoming "no patterns"
        // would mute exactly the operator who's trying to mute things.
        $patterns = $cfg['silenced_patterns'] ?? [];
        if (! is_array($patterns)) {
            throw new QueueInsightsConfigException(
                'queue-insights.silenced_patterns must be a list of glob strings (got ' . get_debug_type($patterns) . ').'
            );
        }

        ConfigValidator::validateSilencedPatterns($patterns);

        $this->registerListeners();
        $this->registerInitiatorMiddleware();
        $this->registerSchedule();
        $this->registerDashboard();
        $this->registerPrometheus();
        $this->registerSchedulerObservability($cfg);
    }

    /**
     * @param  array<array-key, mixed>  $cfg
     */
    private function registerSchedulerObservability(array $cfg): void
    {
        $scheduler = is_array($cfg['scheduler'] ?? null) ? $cfg['scheduler'] : [];
        if (($scheduler['enabled'] ?? false) !== true) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);
        if ($events instanceof Dispatcher) {
            $events->listen(
                ScheduledTaskStarting::class,
                RecordScheduledTaskStarting::class,
            );
            $events->listen(
                ScheduledTaskFinished::class,
                RecordScheduledTaskFinished::class,
            );
            $events->listen(
                ScheduledTaskFailed::class,
                RecordScheduledTaskFailed::class,
            );
            $events->listen(
                ScheduledTaskSkipped::class,
                RecordScheduledTaskSkipped::class,
            );
            $events->listen(
                ScheduledBackgroundTaskFinished::class,
                RecordScheduledBackgroundTaskFinished::class,
            );
        }

        // Rebuild the snapshot once every provider has registered its
        // tasks. `app->booted` fires after register/boot finish on
        // every provider in the stack, so `Schedule::events()` is fully
        // populated by then. The `scheduler.snapshot_rebuild` flag is
        // read inside the callback (not here) so a downstream provider
        // that flips it off — e.g. the workbench preview which
        // pre-seeds the keys with synthetic fixtures — wins over the
        // default.
        $this->app->booted(function (): void {
            if (! Config::bool('scheduler.snapshot_rebuild', true)) {
                return;
            }

            if (! $this->app->bound(Schedule::class)) {
                return;
            }

            $this->app->make(ScheduleSnapshotter::class)->rebuild();
        });
    }

    private function registerDashboard(): void
    {
        if (! Config::bool('dashboard.enabled', true)) {
            return;
        }

        if (! class_exists(LivewireComponent::class)) {
            Log::info('queue-insights: dashboard disabled, livewire/livewire not installed');

            return;
        }

        Livewire::component('queue-insights-dashboard', QueueInsightsDashboard::class);
        // The alert-rules panel is a `#[Lazy]` child component — registering
        // it here keeps the parent dashboard's initial render free of the
        // panel's builder + 98-line blade pass.
        Livewire::component('queue-insights-alert-rules-panel', AlertRulesPanel::class);
        // Schedule observability panel — also lazy. Renders empty body when
        // `scheduler.enabled = false` so the tab strip can decide whether
        // to surface the tab without paying for a full reader pass.
        Livewire::component('queue-insights-schedule-panel', ScheduleInsightsPanel::class);

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    private function registerPrometheus(): void
    {
        if (! Config::bool('prometheus.enabled', false)) {
            return;
        }

        // Loaded independently of the dashboard's `routes/web.php` so a
        // headless production replica (dashboard.enabled = false) can
        // still expose /metrics for cluster-internal scrape.
        $router = $this->app->make(Router::class);
        if (method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware('queue-insights.prometheus-auth', PrometheusAuthMiddleware::class);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/prometheus.php');
    }

    private function registerSchedule(): void
    {
        if (! Config::bool('schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('queue-insights:snapshot')
                ->everyMinute()
                ->withoutOverlapping();

            if (Config::bool('scheduler.enabled', false) && Config::bool('scheduler.sweeper.enabled', true)) {
                // `onOneServer` so multi-host installs don't double-flag a
                // single missed/hung run; `withoutOverlapping` so a long
                // sweep pass never stacks behind the next minute's tick.
                $schedule->command('queue-insights:schedule:sweep')
                    ->everyMinute()
                    ->onOneServer()
                    ->withoutOverlapping();
            }
        });
    }

    private function registerListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        if (! $events instanceof Dispatcher) {
            return;
        }

        $events->listen(JobQueued::class, RecordJobQueued::class);
        $events->listen(JobProcessing::class, RecordJobProcessing::class);
        $events->listen(JobProcessed::class, RecordJobProcessed::class);
        $events->listen(JobFailed::class, RecordJobFailed::class);

        // Initiator origin capture for artisan commands — gated at runtime
        // on `initiator.enabled` inside the listener so a config change
        // doesn't need a redeploy. The daemon skip-list keeps the worker's
        // own command name from leaking as a job origin.
        $events->listen(CommandStarting::class, SetInitiatorOriginFromCommand::class);
    }

    /**
     * Append the HTTP initiator-origin middleware to the `web` / `api`
     * groups so jobs dispatched during a request carry `http:{route}` on
     * hidden `Context`. Gated on `initiator.enabled` — disabled installs
     * never touch the request pipeline.
     */
    private function registerInitiatorMiddleware(): void
    {
        if (! Config::bool('initiator.enabled', true)) {
            return;
        }

        $router = $this->app->make(Router::class);
        if (! $router instanceof Router) {
            return;
        }

        foreach (['web', 'api'] as $group) {
            $router->pushMiddlewareToGroup($group, SetInitiatorOrigin::class);
        }
    }
}
