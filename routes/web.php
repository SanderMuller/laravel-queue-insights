<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;

$base = config('queue-insights.dashboard.path', 'queue-insights');
$middleware = (array) config('queue-insights.dashboard.middleware', ['web', 'auth', 'can:viewQueueInsights']);

// The explicit `@__invoke` is the action Laravel would build anyway; spelling
// it out skips the `method_exists` check that autoloads the component (and
// Livewire's base class) on every uncached-route request.

Route::get($base, QueueInsightsDashboard::class . '@__invoke')
    ->middleware($middleware)
    ->name('queue-insights.dashboard');

// Connection validation lives in QueueInsightsDashboard::mount, not on the
// route definition. Validating at boot via `whereIn(ConfiguredConnections::all())`
// would force any host that mutates `queue-insights.snapshots` at runtime
// (e.g. tests, multi-tenant config refresh) to also rebuild the route table —
// a fragile coupling. The component's mount aborts 404 on an unconfigured
// `{connection}` and is exercised by the existing dashboard tests.
Route::get($base . '/{connection}', QueueInsightsDashboard::class . '@__invoke')
    ->middleware($middleware)
    ->name('queue-insights.connection');
