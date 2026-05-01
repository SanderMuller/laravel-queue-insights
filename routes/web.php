<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;

$base = config('queue-insights.dashboard.path', 'queue-insights');
$middleware = (array) config('queue-insights.dashboard.middleware', ['web', 'auth', 'can:viewQueueInsights']);

Route::get($base, QueueInsightsDashboard::class)
    ->middleware($middleware)
    ->name('queue-insights.dashboard');

/** @noinspection PhpInternalEntityUsedInspection */
Route::get($base . '/{connection}', QueueInsightsDashboard::class)
    ->middleware($middleware)
    ->whereIn('connection', ConfiguredConnections::all())
    ->name('queue-insights.connection');
