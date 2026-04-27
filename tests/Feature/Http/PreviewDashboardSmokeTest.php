<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Workbench\App\Http\Livewire\PreviewDashboard;

beforeEach(function (): void {
    // The retry buttons are gate-checked at render time. Match the workbench
    // provider's permissive gate so the preview component can fully render
    // its bulk-retry chrome under tests.
    Gate::define('retryFailedJobs', static fn (): bool => true);
});

it('PreviewDashboard renders without undefined-variable errors', function (): void {
    // The preview seeder duplicates the data shape passed by the real
    // QueueInsightsDashboard. When new view variables land (batches,
    // batchesEnabled, etc.) the seeder has to mirror them or this smoke
    // test starts surfacing `Undefined variable` notices from blade.
    Livewire::test(PreviewDashboard::class)
        ->assertOk()
        ->assertSee('Queues')
        ->assertSee('Batches');
});
