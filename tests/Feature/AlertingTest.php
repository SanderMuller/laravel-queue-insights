<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Events\QueueDepthExceeded;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.alerts.enabled', true);
    config()->set('queue-insights.alerts.cooldown_seconds', 900);
});

function depthDriver(int $depth): QueueSnapshotDriver
{
    return new readonly class ($depth) implements QueueSnapshotDriver {
        public function __construct(private int $d) {}

        public function depth(string $queue): int
        {
            return $this->d;
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

it('fires QueueDepthExceeded when depth reaches the threshold', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthDriver(1500));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000],
    ]);

    Event::fake([QueueDepthExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(QueueDepthExceeded::class, fn (QueueDepthExceeded $e): bool => $e->connection === 'sqsq'
        && $e->queue === 'work'
        && $e->depth === 1500
        && $e->threshold === 1000);
});

it('does not fire QueueDepthExceeded below the threshold', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthDriver(100));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000],
    ]);

    Event::fake([QueueDepthExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(QueueDepthExceeded::class);
});

it('respects the cooldown: only the first snapshot within the window fires the event', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthDriver(2000));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000],
    ]);

    Event::fake([QueueDepthExceeded::class]);

    Artisan::call('queue-insights:snapshot');
    Artisan::call('queue-insights:snapshot');
    Artisan::call('queue-insights:snapshot');

    Event::assertDispatchedTimes(QueueDepthExceeded::class, 1);
});

it('fires again after the cooldown expires', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthDriver(2000));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000],
    ]);

    Event::fake([QueueDepthExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    // Simulate cooldown expiry by deleting the cooldown key.
    Redis::connection('default')->command('del', [KeyPrefix::make('alert:cooldown:sqsq:work')]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatchedTimes(QueueDepthExceeded::class, 2);
});

it('does not alert when alerts.enabled = false', function (): void {
    config()->set('queue-insights.alerts.enabled', false);
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => depthDriver(5000));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 1000],
    ]);

    Event::fake([QueueDepthExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(QueueDepthExceeded::class);
});
