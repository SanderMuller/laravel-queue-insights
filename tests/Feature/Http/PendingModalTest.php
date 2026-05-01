<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);

    config()->set('queue.connections.myredis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myredis', 'queue' => 'work'],
    ]);
});

/**
 * Mirror of the helper used in PendingSectionTest. Kept local so the modal
 * tests can run independently of that suite.
 */
function seedPendingForModal(string $uuid, string $connection, string $queue, string $class, int $availableAt, int $queuedAt): void
{
    foreach ([
        'connection' => $connection,
        'queue' => $queue,
        'class' => $class,
        'queued_at' => (string) $queuedAt,
        'available_at' => (string) $availableAt,
        'batch_id' => '',
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:' . $uuid, $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:' . $connection . ':' . $queue, $availableAt, $uuid]);
}

it('renders the pending row as a clickable button wired to openPending', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('pending-clickable', 'myredis', 'work', 'App\\Jobs\\Clickable', $now - 5, $now - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('wire:click="openPending(\'pending-clickable\')"')
        ->assertSeeHtml('role="button"')
        ->assertSee('Clickable');
});

it('openPending populates selectedPendingUuid and closePending clears it', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('selectedPendingUuid', null)
        ->call('openPending', 'pending-uuid-x')
        ->assertSet('selectedPendingUuid', 'pending-uuid-x')
        ->call('closePending')
        ->assertSet('selectedPendingUuid', null);
});

it('renders the modal with class + connection + queue + UUID for an open pending row', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('pending-modal-1', 'myredis', 'work', 'App\\Jobs\\OpenedPending', $now - 30, $now - 30);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'pending-modal-1')
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-labelledby="qi-pending-modal-title"')
        ->assertSee('Pending job')
        ->assertSee('OpenedPending')
        ->assertSee('myredis')
        ->assertSee('work')
        ->assertSee('pending-modal-1');
});

it('renders Delayed-job header + Runs metric when the row is delayed', function (): void {
    $now = Date::now()->getTimestamp();
    // Available 10 minutes from now → delayed.
    seedPendingForModal('delayed-modal-1', 'myredis', 'work', 'App\\Jobs\\OpenedDelayed', $now + 600, $now);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'delayed-modal-1')
        ->assertSee('Delayed job')
        ->assertSee('OpenedDelayed')
        ->assertSee('Runs');
});

it('shows the "no longer pending" empty state when a worker grabs the row mid-modal', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('vanish-uuid', 'myredis', 'work', 'App\\Jobs\\WillVanish', $now - 5, $now - 5);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'vanish-uuid');

    // Simulate the JobProcessing listener clearing the pending hash + zset.
    R::conn()->command('del', ['qmtest:pending:vanish-uuid']);
    R::conn()->command('zrem', ['qmtest:pending-zset:myredis:work', 'vanish-uuid']);

    // Re-render — the open uuid is still set but the row is gone.
    $component
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', 'vanish-uuid')
        ->assertSee('No longer pending');
});

it('hydrates the modal directly from the pending hash when the uuid is outside the top-50 aggregate window', function (): void {
    // Codex regression: clicking a batched-job item from the batch modal
    // sets `selectedPendingUuid`, but if that uuid sits beyond the 50-row
    // global pending/in-flight aggregates, the prior `resolveSelectedPending`
    // returned null and the modal flashed "no longer pending" even though
    // the pending hash still existed. Direct-by-uuid hydration must surface
    // the row regardless of the aggregate window.
    $now = Date::now()->getTimestamp();
    seedPendingForModal('uuid-outside-window', 'myredis', 'work', 'App\\Jobs\\OutsideWindow', $now - 5, $now - 5);

    // Seed > 50 newer pending rows so the target uuid falls outside the
    // global cap allPendingJobs / allInFlightJobs would sample.
    for ($i = 0; $i < 60; ++$i) {
        seedPendingForModal('filler-' . $i, 'myredis', 'work', 'App\\Jobs\\Filler', $now - 1, $now - 1);
    }

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'uuid-outside-window')
        ->assertSee('OutsideWindow')
        ->assertDontSee('No longer pending');
});

it('does not mount the modal when pending tracking is disabled', function (): void {
    config()->set('queue-insights.pending.enabled', false);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'whatever-uuid')
        // The component still records the click, but the blade gate hides
        // the modal entirely so the closed dashboard doesn't half-render.
        ->assertDontSeeHtml('aria-labelledby="qi-pending-modal-title"');
});
