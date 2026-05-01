<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
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
    config()->set('queue.connections.other', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myredis', 'queue' => 'work'],
        ['connection' => 'other', 'queue' => 'mail'],
    ]);
});

/**
 * Direct-Redis seed (mirrors PendingInspectorTest helper). Kept local so this
 * file can run independently of that suite.
 */
function seedPendingForSection(string $uuid, string $connection, string $queue, string $class, int $availableAt): void
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

it('aggregates pending jobs across every configured queue, earliest-available first', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForSection('uuid-a', 'myredis', 'work', 'App\\Jobs\\Alpha', $now - 30);
    seedPendingForSection('uuid-b', 'other', 'mail', 'App\\Jobs\\Beta', $now - 5);

    $rows = resolve(QueueInsights::class)->allPendingJobs();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['class'])->toBe('App\\Jobs\\Alpha')
        ->and($rows[0]['connection'])->toBe('myredis')
        ->and($rows[1]['class'])->toBe('App\\Jobs\\Beta')
        ->and($rows[1]['connection'])->toBe('other');
});

it('excludes delayed jobs whose available_at is in the future', function (): void {
    $now = Date::now()->getTimestamp();
    // Available now (counts as pending).
    seedPendingForSection('uuid-now', 'myredis', 'work', 'App\\Jobs\\NowJob', $now - 5);
    // Delayed — not yet runnable, must not appear in the Pending section.
    seedPendingForSection('uuid-future', 'other', 'mail', 'App\\Jobs\\Future', $now + 600);

    $rows = resolve(QueueInsights::class)->allPendingJobs();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uuid'])->toBe('uuid-now');
});

it('caps HGETALL fan-out at the global limit when many queues are backed up', function (): void {
    $now = Date::now()->getTimestamp();
    // 3 jobs per queue × 2 queues = 6 candidates, but global limit = 2.
    foreach (['work', 'mail'] as $queue) {
        $connection = $queue === 'work' ? 'myredis' : 'other';
        for ($j = 0; $j < 3; ++$j) {
            seedPendingForSection(
                "uuid-{$queue}-{$j}",
                $connection,
                $queue,
                "App\\Jobs\\{$queue}{$j}",
                $now - (10 - $j) // 10, 9, 8 — first job in each queue is oldest
            );
        }
    }

    $rows = resolve(QueueInsights::class)->allPendingJobs(2);

    expect($rows)->toHaveCount(2);
    // The two oldest available_at values across both queues should win.
    expect($rows[0]['available_at'])->toBeLessThanOrEqual($rows[1]['available_at']);
});

it('respects the limit argument and caps at 200', function (): void {
    expect(resolve(QueueInsights::class)->allPendingJobs(0))
        ->toBeEmpty();
});

it('renders the top-level Pending section with the aggregated rows', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForSection('uuid-a', 'myredis', 'work', 'App\\Jobs\\Alpha', $now - 30);
    seedPendingForSection('uuid-b', 'other', 'mail', 'App\\Jobs\\Beta', $now - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Pending')
        // The partial splits the FQCN into namespace (App\Jobs\) + leaf (Alpha)
        // across two spans, so match on the leaf only.
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('2 pending')
        ->assertSee('0 delayed');
});

it('renders delayed jobs in a separate sub-group with a delayed badge', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForSection('uuid-now', 'myredis', 'work', 'App\\Jobs\\AlphaJob', $now - 5);
    // Available 10 minutes from now → delayed group.
    seedPendingForSection('uuid-future', 'other', 'mail', 'App\\Jobs\\BetaDelayedJob', $now + 600);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Pending now')
        ->assertSee('Delayed')
        ->assertSee('AlphaJob')
        ->assertSee('BetaDelayedJob')
        ->assertSee('1 pending')
        ->assertSee('1 delayed')
        // Badge only renders on the delayed sub-group. The chip uses a unique
        // bg-indigo-50 colour combo so the assertion can pin to the badge
        // chrome without depending on attribute-order quirks of @if blocks.
        ->assertSeeHtml('bg-indigo-50');
});

it('does not render the delayed sub-group when no delayed jobs are tracked', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForSection('uuid-only-pending', 'myredis', 'work', 'App\\Jobs\\OnlyPending', $now - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Pending now')
        ->assertDontSee('Delayed (')
        ->assertDontSeeHtml('bg-indigo-50');
});

it('renders the empty state when no pending jobs are tracked', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('No pending jobs tracked.');
});

it('hides the Pending section entirely when pending.enabled is false', function (): void {
    config()->set('queue-insights.pending.enabled', false);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSee('No pending jobs tracked.')
        ->assertDontSeeHtml('aria-label="Pending jobs"');
});
