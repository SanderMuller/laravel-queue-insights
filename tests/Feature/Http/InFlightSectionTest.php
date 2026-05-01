<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
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
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myredis', 'queue' => 'work'],
    ]);
});

/**
 * Direct-Redis seed for an in-flight job. Mirrors what `RecordJobProcessing`
 * writes once it's stamped state + started_at on the pending hash and moved
 * the uuid from pending-zset → inflight-zset.
 */
function seedInFlight(string $uuid, string $connection, string $queue, string $class, int $startedAt, int $queuedAt): void
{
    foreach ([
        'connection' => $connection,
        'queue' => $queue,
        'class' => $class,
        'queued_at' => (string) $queuedAt,
        'available_at' => (string) $queuedAt,
        'state' => 'in_flight',
        'started_at' => (string) $startedAt,
        'batch_id' => '',
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:' . $uuid, $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:inflight-zset:' . $connection . ':' . $queue, $startedAt, $uuid]);
}

it('allInFlightJobs aggregates in-flight rows across queues, longest-running first', function (): void {
    $now = Date::now()->getTimestamp();
    seedInFlight('inflight-recent', 'myredis', 'work', 'App\\Jobs\\RecentJob', $now - 3, $now - 5);
    seedInFlight('inflight-stuck', 'myredis', 'work', 'App\\Jobs\\StuckJob', $now - 600, $now - 605);

    $rows = resolve(QueueInsights::class)->allInFlightJobs();

    expect($rows)->toHaveCount(2)
        // Longest-running first — `started_at` ascending = oldest start time.
        ->and($rows[0]['uuid'])->toBe('inflight-stuck')
        ->and($rows[0]['state'])->toBe('in_flight')
        ->and($rows[1]['uuid'])->toBe('inflight-recent');
});

it('renders the In-flight sub-group with running badge in the dashboard', function (): void {
    $now = Date::now()->getTimestamp();
    seedInFlight('inflight-render', 'myredis', 'work', 'App\\Jobs\\InflightVisible', $now - 30, $now - 60);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('In-flight')
        ->assertSee('1 in-flight')
        ->assertSee('InflightVisible')
        // The running chip uses an amber colour and an animated dot. Pin to
        // the unique colour combo so the assertion catches a chip removal
        // without depending on attribute-order quirks.
        ->assertSeeHtml('bg-amber-50');
});

it('opens the in-flight modal variant with Started + Running for tiles', function (): void {
    $now = Date::now()->getTimestamp();
    seedInFlight('inflight-modal', 'myredis', 'work', 'App\\Jobs\\InflightModal', $now - 90, $now - 120);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'inflight-modal')
        ->assertSee('In-flight job')
        ->assertSee('InflightModal')
        ->assertSee('Started')
        ->assertSee('Running for');
});

it('orders in-flight rows by started_at when queue time and start time diverge', function (): void {
    // Codex regression: a delayed job queued long ago but started recently
    // must NOT come before a job that's actually been running longer. The
    // zset stores `started_at` so the cross-queue sort needs to preserve
    // that score end-to-end, not fall back to the hash's `available_at`.
    $now = Date::now()->getTimestamp();

    // Delayed-then-picked-up: queued 10 minutes ago, started 5 seconds ago.
    seedInFlight('inflight-fresh', 'myredis', 'work', 'App\\Jobs\\FreshlyStarted', $now - 5, $now - 600);
    // Plain in-flight: queued recently, started a minute ago.
    seedInFlight('inflight-stuck', 'myredis', 'work', 'App\\Jobs\\StuckRunner', $now - 60, $now - 65);

    $rows = resolve(QueueInsights::class)->allInFlightJobs();

    expect($rows)->toHaveCount(2)
        // Longest-running first: stuck (60s) before fresh (5s), regardless
        // of which one was queued first.
        ->and($rows[0]['uuid'])->toBe('inflight-stuck')
        ->and($rows[1]['uuid'])->toBe('inflight-fresh');
});

it('drops in-flight zset entry when JobProcessed fires', function (): void {
    $now = Date::now()->getTimestamp();
    seedInFlight('inflight-finished', 'myredis', 'work', 'App\\Jobs\\Finished', $now - 5, $now - 10);

    expect(R::int('zcard', 'qmtest:inflight-zset:myredis:work'))->toBe(1);

    // Drive the listener directly. The cleanup branch keys off the zset name,
    // so a real JobProcessed event would fire the same DEL/ZREM chain.
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('inflight-finished');
    $job->shouldReceive('getQueue')->andReturn('work');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\Finished']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Finished');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn('inflight-finished');

    $event = new JobProcessed(connectionName: 'myredis', job: $job);
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('zcard', 'qmtest:inflight-zset:myredis:work'))->toBe(0)
        ->and(R::int('exists', 'qmtest:pending:inflight-finished'))->toBe(0);
});
