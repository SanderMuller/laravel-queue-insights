<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;

Route::get(config('queue-insights.dashboard.path', 'queue-insights'), QueueInsightsDashboard::class)
    ->middleware((array) config('queue-insights.dashboard.middleware', ['web', 'auth', 'can:viewQueueInsights']))
    ->name('queue-insights.dashboard');
