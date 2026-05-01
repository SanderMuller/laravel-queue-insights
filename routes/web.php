<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;

$base = config('queue-insights.dashboard.path', 'queue-insights');
$middleware = (array) config('queue-insights.dashboard.middleware', ['web', 'auth', 'can:viewQueueInsights']);

Route::get($base, QueueInsightsDashboard::class)
    ->middleware($middleware)
    ->name('queue-insights.dashboard');

// Connection validation lives in QueueInsightsDashboard::mount, not on the
// route definition. Validating at boot via `whereIn(ConfiguredConnections::all())`
// would force any host that mutates `queue-insights.snapshots` at runtime
// (e.g. tests, multi-tenant config refresh) to also rebuild the route table —
// a fragile coupling. The component's mount aborts 404 on an unconfigured
// `{connection}` and is exercised by the existing dashboard tests.
Route::get($base . '/{connection}', QueueInsightsDashboard::class)
    ->middleware($middleware)
    ->name('queue-insights.connection');
