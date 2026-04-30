<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
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
use SanderMuller\QueueInsights\Console\QueueInsightsSnapshotCommand;
use SanderMuller\QueueInsights\Console\QueueInsightsWorkCommand;
use SanderMuller\QueueInsights\Console\WorkerOutputStreams;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Http\Livewire\AlertRulesPanel;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use SanderMuller\QueueInsights\Support\Sanitizers\KeyRedactingSanitizer;
use SanderMuller\QueueInsights\Support\Sanitizers\MetadataOnlySanitizer;

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

        $this->registerListeners();
        $this->registerSchedule();
        $this->registerDashboard();
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
