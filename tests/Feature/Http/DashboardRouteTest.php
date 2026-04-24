<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

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

it('renders the dashboard end-to-end via HTTP (layout + component + redis reads)', function (): void {
    // Full-stack smoke: catches Livewire layout-resolution bugs, missing-template errors,
    // and driver-divergent Redis ops in one pass. Skip if Redis isn't available since the
    // Livewire render touches live metrics + recent completed stream.
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);

    Gate::define('viewQueueInsights', fn (?User $user = null): bool => true);

    $user = new User();
    $user->forceFill(['id' => 1, 'name' => 'dev', 'email' => 'dev@example.test']);

    $response = test()->actingAs($user)->get('/queue-insights');

    $response->assertOk();
    $response->assertSee('Queue Insights');
    $response->assertSee('No queues configured');
});
