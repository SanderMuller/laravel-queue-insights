<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reuse the workbench preview wiring (config overrides, permissive
        // Gate, seeded `/` route) so the hosted demo behaves identically
        // to `vendor/bin/testbench serve` running locally. Keeps demo
        // logic out of the package's public surface.
        $this->app->register(WorkbenchServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
