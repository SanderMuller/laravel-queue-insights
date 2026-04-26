<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Workbench\App\Http\Livewire\PreviewDashboard;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('preview-dashboard', PreviewDashboard::class);

        // Permissive Gate so the preview can render the retry buttons (per-job
        // in the failed-modal, bulk above the failed list when filtered). The
        // real package defines NO default gate — hosts must opt in by
        // declaring `retryFailedJobs` themselves. We define it here only for
        // the local-dev preview app so the UI is fully demo-able.
        Gate::define('retryFailedJobs', static fn (): bool => true);

        Route::middleware('web')->get('/', PreviewDashboard::class);
    }
}
