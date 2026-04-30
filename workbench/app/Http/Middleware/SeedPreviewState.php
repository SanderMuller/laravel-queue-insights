<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Support\PreviewSeeder;

/**
 * Hydrates the preview's Redis fixtures + failed_jobs DB rows before the
 * request hits the real `QueueInsightsDashboard` Livewire component.
 *
 * The seeder is bound as a singleton by `WorkbenchServiceProvider` so its
 * "seeded once" guard survives Livewire's polling round-trips inside one
 * request lifecycle without thrashing Redis.
 */
final readonly class SeedPreviewState
{
    public function __construct(private PreviewSeeder $seeder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->seeder->seed();

        return $next($request);
    }
}
