<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights;

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
use SanderMuller\QueueInsights\Console\QueueInsightsPrometheusPushCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsSnapshotCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsWorkCommand;
use SanderMuller\QueueInsights\Console\WorkerOutputStreams;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Http\Livewire\AlertRulesPanel;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
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
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAgeCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotAliveCollector;
use SanderMuller\QueueInsights\Prometheus\Collectors\SnapshotErrorsCollector;
use SanderMuller\QueueInsights\Prometheus\PrometheusAuthMiddleware;
use SanderMuller\QueueInsights\Prometheus\Registry as PrometheusRegistry;
use SanderMuller\QueueInsights\Prometheus\Renderer as PrometheusRenderer;
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
        ConfigValidator::validateWork($section($cfg, 'work'));
        ConfigValidator::validateRetention($section($cfg, 'retention'));
        ConfigValidator::validatePrometheus($section($cfg, 'prometheus'));

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
        $this->registerSchedule();
        $this->registerDashboard();
        $this->registerPrometheus();
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
    }
}
