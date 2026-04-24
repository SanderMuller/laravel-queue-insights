<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;

it('registers the dashboard route by default', function (): void {
    expect(Route::has('queue-insights.dashboard'))->toBeTrue();
});

it('applies the configured middleware stack to the dashboard route', function (): void {
    $route = Route::getRoutes()->getByName('queue-insights.dashboard');

    expect($route)->not->toBeNull();
    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('web')
        ->toContain('auth');
});

it('skips route registration when dashboard.enabled = false', function (): void {
    config()->set('queue-insights.dashboard.enabled', false);
    Route::setRoutes(new RouteCollection());

    (new QueueInsightsServiceProvider(app()))->boot();

    expect(Route::has('queue-insights.dashboard'))->toBeFalse();
});
