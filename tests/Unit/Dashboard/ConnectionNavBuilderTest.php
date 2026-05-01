<?php declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use SanderMuller\QueueInsights\Dashboard\ConnectionNavBuilder;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;

beforeEach(function (): void {
    config()->set('queue-insights.snapshots', []);
    Route::get('/queue-insights', QueueInsightsDashboard::class)
        ->name('queue-insights.dashboard');
    Route::get('/queue-insights/{connection}', QueueInsightsDashboard::class)
        ->name('queue-insights.connection');
});

it('build suppresses the strip when zero or one connections are configured', function (): void {
    config()->set('queue-insights.snapshots', []);
    expect((new ConnectionNavBuilder())->build(null)['should_render'])->toBeFalse();

    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
    expect((new ConnectionNavBuilder())->build(null)['should_render'])->toBeFalse();
});

it('build emits an All tab plus one tab per connection in config order', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    $nav = (new ConnectionNavBuilder())->build(null);

    expect($nav['should_render'])->toBeTrue()
        ->and($nav['tabs'])->toHaveCount(3)
        ->and($nav['tabs'][0]['name'])->toBeNull()
        ->and($nav['tabs'][0]['label'])->toBe('All')
        ->and($nav['tabs'][0]['active'])->toBeTrue()
        ->and($nav['tabs'][0]['tooltip'])->toBeNull()
        ->and($nav['tabs'][1]['name'])->toBe('redis')
        ->and($nav['tabs'][2]['name'])->toBe('sqs');
});

it('build marks the active tab matching the current scope', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    $nav = (new ConnectionNavBuilder())->build('sqs');

    expect($nav['tabs'][0]['active'])->toBeFalse()
        ->and($nav['tabs'][1]['active'])->toBeFalse()
        ->and($nav['tabs'][2]['active'])->toBeTrue();
});

it('build drops gate-denied tabs AND the All tab when any denial exists', function (): void {
    // Codex review #1: the un-scoped base route 403s when the per-connection
    // gate denies any monitored connection. Dropping the "All" tab here
    // prevents operators from clicking through to that 403.
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
        ['connection' => 'highmem', 'queue' => 'reports'],
    ]);

    Gate::define('viewQueueInsightsConnection', static fn (?User $user, string $connection): bool => $connection !== 'highmem');

    $nav = (new ConnectionNavBuilder())->build(null);

    expect($nav['tabs'])->toHaveCount(2)
        ->and(collect($nav['tabs'])->pluck('name')->all())->toBe(['redis', 'sqs']);
});

it('build suppresses the strip when the gate restricts the operator to a single connection', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    Gate::define('viewQueueInsightsConnection', static fn (?User $user, string $connection): bool => $connection === 'redis');

    expect((new ConnectionNavBuilder())->build('redis')['should_render'])->toBeFalse();
});

it('build URLs route to the named dashboard + connection routes', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    $nav = (new ConnectionNavBuilder())->build(null);

    expect($nav['tabs'][0]['url'])->toContain('/queue-insights')
        ->and($nav['tabs'][1]['url'])->toContain('/queue-insights/redis')
        ->and($nav['tabs'][2]['url'])->toContain('/queue-insights/sqs');
});
