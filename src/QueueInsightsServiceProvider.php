<?php

declare(strict_types=1);

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
use SanderMuller\QueueInsights\Console\QueueInsightsSnapshotCommand;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
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

        $this->app->bind(PayloadSanitizer::class, fn (): PayloadSanitizer => match (Config::string('capture.payloads', 'off')) {
            'metadata' => new MetadataOnlySanitizer(),
            'full' => new KeyRedactingSanitizer(
                array_values(array_filter(
                    Config::array('capture.redact_keys'),
                    is_string(...),
                )),
                Config::int('capture.max_field_bytes', 2048),
                Config::int('capture.max_payload_bytes', 16384),
            ),
            default => new MetadataOnlySanitizer(),
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
            ]);
        }

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'queue-insights');

        if (! Config::bool('enabled', true)) {
            return;
        }

        ConfigValidator::validateSnapshots(Config::array('snapshots'));
        ConfigValidator::validatePending(Config::array('pending'));

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
