<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Workbench\App\Http\Livewire\PreviewDashboard;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('preview-dashboard', PreviewDashboard::class);

        Route::middleware('web')->get('/', PreviewDashboard::class);
    }
}
