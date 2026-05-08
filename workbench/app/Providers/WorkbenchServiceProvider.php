<?php declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use Workbench\App\Http\Middleware\SeedPreviewState;
use Workbench\App\Support\PreviewSeeder;

final class WorkbenchServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Singleton so the seeded-once guard survives Livewire polling
        // within a single page render — repeated middleware hits don't
        // re-flush + re-seed the keyspace.
        $this->app->singleton(PreviewSeeder::class);

        // Apply the QI config in register() (BEFORE QueueInsightsServiceProvider
        // boots) so the package's `routes/web.php` reads the seeded snapshots
        // when computing the `{connection}` whereIn constraint. Otherwise the
        // bundled connection-scoped route would mount with an empty allowed
        // list and 404 every variant.
        PreviewSeeder::applyConfig();
    }

    public function boot(): void
    {
        // Apply the package config (key_prefix, snapshots, alerts,
        // chain_lineage) on every request — including Livewire's
        // `/livewire/update` polling endpoint, which never passes through
        // the seeder middleware. Without this the dashboard would read
        // from the host's default `qm:` prefix on polls and render every
        // section blank ten seconds after the page loaded.
        PreviewSeeder::applyConfig();

        // The preview's seeded fixtures are static — every wire:poll
        // would be wasted Redis traffic. Hosts running the real
        // dashboard still default-on for live snapshots + alerts.
        config()->set('queue-insights.dashboard.polling', false);

        // Permissive Gate so the preview is fully demo-able without an
        // authenticated user. `Gate::before` short-circuits every check
        // (viewQueueInsights, retryFailedJobs, anything the package adds
        // later) — workbench-only, never shipped. Closures via
        // `Gate::define` would short-circuit to false for guests because
        // Laravel's gate resolver defaults to denying unauthenticated
        // users regardless of the closure body.
        Gate::before(static fn (): bool => true);

        // The `/` route mounts the REAL `QueueInsightsDashboard` Livewire
        // component (Livewire 3 routable-class shortcut), with the seeder
        // middleware running first so every section reads from fresh
        // Redis fixtures rather than a hand-built view-data array.
        Route::middleware(['web', SeedPreviewState::class])
            ->get('/', QueueInsightsDashboard::class);
    }
}
