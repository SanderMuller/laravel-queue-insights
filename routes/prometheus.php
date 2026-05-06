<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Prometheus\MetricsController;

$path = config('queue-insights.prometheus.path', 'metrics');
if (! is_string($path) || $path === '') {
    $path = 'metrics';
}

// `middleware` defaults to `null` in the package config so a missing key
// can be distinguished from an explicit override. `null` → use the
// package default gate (`queue-insights.prometheus-auth`); an explicit
// array (including `[]` to expose `/metrics` raw behind outer infra
// auth) is honoured verbatim per the config docblock.
$middleware = config('queue-insights.prometheus.middleware');
if (! is_array($middleware)) {
    $middleware = ['queue-insights.prometheus-auth'];
}

Route::get($path, MetricsController::class)
    ->middleware($middleware)
    ->name('queue-insights.metrics');
