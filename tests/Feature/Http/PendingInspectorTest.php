<?php

declare(strict_types=1);

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
    config()->set('queue-insights.pending.gap_warn_threshold', 5);

    config()->set('queue.connections.myredis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myredis', 'queue' => 'work'],
    ]);
});

/**
 * Seed a pending entry directly into the Redis storage. Used so the view
 * test doesn't depend on a queue worker firing JobQueued.
 */
function seedPendingDirect(string $uuid, string $connection, string $queue, string $class, int $availableAt): void
{
    foreach ([
        'connection' => $connection,
        'queue' => $queue,
        'class' => $class,
        'queued_at' => (string) ($availableAt - 1),
        'available_at' => (string) $availableAt,
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:' . $uuid, $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:' . $connection . ':' . $queue, $availableAt, $uuid]);
}

it('renders the inspector toggle button with the tracked count when pending tracking exists', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPendingDirect('uuid-a', 'myredis', 'work', 'App\\Jobs\\Alpha', $now - 10);
    seedPendingDirect('uuid-b', 'myredis', 'work', 'App\\Jobs\\Beta', $now + 60);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('2 queued')
        ->assertSeeHtml('wire:click="toggleQueueInspector(\'myredis:work\')"');
});

it('does not render the toggle button when pending.enabled is false', function (): void {
    config()->set('queue-insights.pending.enabled', false);

    seedPendingDirect('uuid-x', 'myredis', 'work', 'App\\X', Date::now()
        ->getTimestamp() - 10);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSee('queued')
        ->assertDontSee('toggleQueueInspector');
});

it('does not render the toggle button when nothing is tracked for the queue', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSee('queued')
        ->assertDontSee('toggleQueueInspector');
});

it('toggleQueueInspector flips and unflips the expanded queue key', function (): void {
    seedPendingDirect('uuid-x', 'myredis', 'work', 'App\\X', Date::now()
        ->getTimestamp() - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('expandedQueueKey', '')
        ->call('toggleQueueInspector', 'myredis:work')
        ->assertSet('expandedQueueKey', 'myredis:work')
        ->call('toggleQueueInspector', 'myredis:work')
        ->assertSet('expandedQueueKey', '');
});

it('renders the pending list inside the expanded inspector', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPendingDirect('uuid-a', 'myredis', 'work', 'App\\Jobs\\Alpha', $now - 30);
    seedPendingDirect('uuid-b', 'myredis', 'work', 'App\\Jobs\\Beta', $now - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->set('expandedQueueKey', 'myredis:work')
        ->assertSee('Pending (2)')
        ->assertSee('App\\Jobs\\Alpha')
        ->assertSee('App\\Jobs\\Beta');
});

it('renders the delayed list inside the expanded inspector with countdowns', function (): void {
    $now = Date::now()
        ->getTimestamp();
    seedPendingDirect('uuid-soon', 'myredis', 'work', 'App\\Jobs\\Soon', $now + 60);
    seedPendingDirect('uuid-far', 'myredis', 'work', 'App\\Jobs\\Far', $now + 3600);

    Livewire::test(QueueInsightsDashboard::class)
        ->set('expandedQueueKey', 'myredis:work')
        ->assertSee('Delayed (2)')
        ->assertSee('App\\Jobs\\Soon')
        ->assertSee('App\\Jobs\\Far')
        // diffForHumans() output for +60s and +3600s:
        ->assertSee('runs');
});

it('renders the tracking-gap badge when the zset diverges from the depth+delayed snapshot', function (): void {
    // Seed depth + delayed via the live keys the snapshot would have written.
    R::conn()->command('setex', ['qmtest:live:depth:myredis:work', 90, '50']);
    R::conn()->command('setex', ['qmtest:live:delayed:myredis:work', 90, '0']);

    // Tracked count is just 2 — gap = 50 - 2 = 48 (>> threshold of 5).
    seedPendingDirect('uuid-a', 'myredis', 'work', 'App\\Jobs\\A', Date::now()
        ->getTimestamp() - 10);
    seedPendingDirect('uuid-b', 'myredis', 'work', 'App\\Jobs\\B', Date::now()
        ->getTimestamp() - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('+48 gap')
        ->set('expandedQueueKey', 'myredis:work')
        ->assertSee('Tracking gap');
});

it('does not render the gap badge when the divergence is under the threshold', function (): void {
    R::conn()->command('setex', ['qmtest:live:depth:myredis:work', 90, '4']);
    R::conn()->command('setex', ['qmtest:live:delayed:myredis:work', 90, '0']);

    // Tracked = 2, depth = 4, gap = 2 (< threshold of 5).
    seedPendingDirect('uuid-a', 'myredis', 'work', 'App\\Jobs\\A', Date::now()
        ->getTimestamp() - 10);
    seedPendingDirect('uuid-b', 'myredis', 'work', 'App\\Jobs\\B', Date::now()
        ->getTimestamp() - 5);

    Livewire::test(QueueInsightsDashboard::class)
        // Specific "+N gap" badge format and the inspector-body banner copy.
        // Asserts on the exact strings the badge renders so an unrelated word
        // "gap" appearing elsewhere on the page can't false-pass the test.
        ->assertDontSeeHtml('gap</span>')
        ->assertDontSee('Tracking gap');
});
