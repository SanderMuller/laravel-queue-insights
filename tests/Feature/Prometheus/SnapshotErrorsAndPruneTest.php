<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
});

function failingSnapshotDriver(): QueueSnapshotDriver
{
    return new class implements QueueSnapshotDriver {
        public function depth(string $queue): int
        {
            throw new RuntimeException('boom');
        }

        public function inFlight(string $queue): ?int
        {
            return null;
        }

        public function delayed(string $queue): ?int
        {
            return null;
        }

        public function canonicalKey(string $queue): string
        {
            return CanonicalQueueKey::from($queue);
        }
    };
}

it('snapshot command increments snapshot-errors-total on driver failure', function (): void {
    config()->set('queue.connections.broken', ['driver' => 'redis']);
    config()->set('queue-insights.driver_overrides.broken', fn () => failingSnapshotDriver());
    config()->set('queue-insights.snapshots', [
        ['connection' => 'broken', 'queue' => 'bad'],
    ]);

    Artisan::call('queue-insights:snapshot');
    Artisan::call('queue-insights:snapshot');

    expect(R::int('get', 'qmtest:snapshot-errors-total:broken:bad'))->toBe(2);
    // Counter has no TTL — Prometheus monotonicity.
    expect(R::int('ttl', 'qmtest:snapshot-errors-total:broken:bad'))->toBe(-1);
});

it('class-roster prune does NOT touch monotonic counter keys (TTL handles aging — codex review fix)', function (): void {
    $now = Date::now()->getTimestamp();
    $aged = $now - (31 * 86400);

    R::conn()->command('zadd', ['qmtest:classes', $aged, 'App\\Jobs\\Ancient']);
    R::conn()->command('zadd', ['qmtest:classes:sqs', $aged, 'App\\Jobs\\Ancient']);

    // Counters seeded WITHOUT TTL — simulating pre-TTL data or in-flight
    // counters from a still-active class. The prune used to DEL these
    // alongside the roster eviction, racing with concurrent listener
    // INCRs and breaking Prometheus monotonicity. Now the prune leaves
    // them alone and the listener-side EXPIRE governs aging.
    R::conn()->command('set', ['qmtest:processed-total:App\\Jobs\\Ancient', '1000']);
    R::conn()->command('set', ['qmtest:processed-total:App\\Jobs\\Ancient:sqs', '900']);
    R::conn()->command('set', ['qmtest:failed-total:App\\Jobs\\Ancient', '50']);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
    config()->set('queue.connections.sqs', ['driver' => 'sync']);

    Artisan::call('queue-insights:snapshot');

    // Counters survive the prune — they age out via per-INCR EXPIRE.
    expect(R::int('get', 'qmtest:processed-total:App\\Jobs\\Ancient'))->toBe(1000)
        ->and(R::int('get', 'qmtest:processed-total:App\\Jobs\\Ancient:sqs'))
        ->toBe(900)
        ->and(R::int('get', 'qmtest:failed-total:App\\Jobs\\Ancient'))
        ->toBe(50);

    // Roster entries DO get evicted — that part is unchanged.
    expect(R::raw('zrange', 'qmtest:classes', 0, -1))->not->toContain('App\\Jobs\\Ancient');
});
