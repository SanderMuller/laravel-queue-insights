<?php declare(strict_types=1);

use App\Http\Middleware\DemoBasicAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Register Artisan commands defined under demo/app/Console/Commands —
    // currently the `demo:spray-jobs` populator (see SprayJobsCommand)
    // that fills the dashboard with a realistic mix of immediate /
    // delayed / batched / failing jobs (each carrying Laravel Context).
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Append the demo basic-auth gate to the `web` middleware group.
        // This covers `/` (mounted by Workbench), `/livewire/update`
        // (the modal request path), and the package's named
        // `queue-insights.*` routes — everything that uses `web`. The
        // health check at `/up` lives outside the web group, so Cloud's
        // probe stays open.
        //
        // The middleware no-ops when DEMO_BASIC_USER + DEMO_BASIC_PASS
        // env vars aren't both set (local dev default).
        $middleware->appendToGroup('web', DemoBasicAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
