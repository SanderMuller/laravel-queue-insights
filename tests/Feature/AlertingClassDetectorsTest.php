<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDetector;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Events\JobClassFailureRateExceeded;
use SanderMuller\QueueInsights\Events\JobClassP95Exceeded;
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
    // Mute queue-scoped rules during these tests.
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.alerts.rules.snapshot_errored.enabled', false);
});

function noopSnapshotConfig(): void
{
    $driver = new readonly class implements QueueSnapshotDriver {
        public function depth(string $queue): int
        {
            return 0;
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

    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => $driver);
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
}

function seedClass(string $class, int $processed, int $failed): void
{
    $bucket = Date::now('UTC')->format('YmdH');
    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('classes'), Date::now()
        ->getTimestamp(), $class]);

    if ($processed > 0) {
        $redis->command('setex', [KeyPrefix::make("processed:{$class}:{$bucket}"), 86400, (string) $processed]);
    }

    if ($failed > 0) {
        $redis->command('setex', [KeyPrefix::make("failed:{$class}:{$bucket}"), 86400, (string) $failed]);
    }
}

it('fires JobClassFailureRateExceeded when ratio crosses threshold and total >= min_jobs', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.failure_rate.min_jobs', 20);
    config()->set('queue-insights.alerts.rules.failure_rate.ratio', 0.10);

    seedClass('App\\Jobs\\Flaky', processed: 80, failed: 20); // 20% > 10%

    Event::fake([JobClassFailureRateExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(JobClassFailureRateExceeded::class, fn (JobClassFailureRateExceeded $e): bool => $e->jobClass === 'App\\Jobs\\Flaky'
        && $e->failed === 20
        && $e->processed === 80
        && $e->total === 100
        && $e->severity === 'warning');
});

it('does not fire failure rate when total is below min_jobs', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.failure_rate.min_jobs', 50);
    config()->set('queue-insights.alerts.rules.failure_rate.ratio', 0.10);

    seedClass('App\\Jobs\\LowVolume', processed: 5, failed: 5); // 50% but only 10 total

    Event::fake([JobClassFailureRateExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(JobClassFailureRateExceeded::class);
});

it('does not fire failure rate when ratio is below threshold', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.failure_rate.min_jobs', 10);
    config()->set('queue-insights.alerts.rules.failure_rate.ratio', 0.20);

    seedClass('App\\Jobs\\Healthy', processed: 95, failed: 5); // 5% < 20%

    Event::fake([JobClassFailureRateExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(JobClassFailureRateExceeded::class);
});

it('only iterates classes present in the qi:classes zset', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.failure_rate.min_jobs', 1);
    config()->set('queue-insights.alerts.rules.failure_rate.ratio', 0.01);

    // Class is NOT added to qi:classes — counters exist but won't be iterated.
    $bucket = Date::now('UTC')->format('YmdH');
    $redis = Redis::connection('default');
    $redis->command('setex', [KeyPrefix::make("processed:App\\Jobs\\Ghost:{$bucket}"), 86400, '1']);
    $redis->command('setex', [KeyPrefix::make("failed:App\\Jobs\\Ghost:{$bucket}"), 86400, '99']);

    Event::fake([JobClassFailureRateExceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(JobClassFailureRateExceeded::class);
});

it('fires JobClassP95Exceeded when class p95 sample exceeds the configured threshold', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.slow_p95.enabled', true);
    config()->set('queue-insights.alerts.rules.slow_p95.class_threshold_ms', [
        'App\\Jobs\\Slow' => 1_000,
    ]);
    // Mute failure_rate to keep this test focused.
    config()->set('queue-insights.alerts.rules.failure_rate.enabled', false);

    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('classes'), Date::now()
        ->getTimestamp(), 'App\\Jobs\\Slow']);

    // 20 samples — bottom 18 cheap, top 2 slow. p95 of 20 → index 18 → 5000.
    $samplesKey = KeyPrefix::make('duration:samples:App\\Jobs\\Slow');
    foreach ([100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 5_000, 5_000] as $ms) {
        $redis->command('rpush', [$samplesKey, (string) $ms]);
    }

    Event::fake([JobClassP95Exceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(JobClassP95Exceeded::class, fn (JobClassP95Exceeded $e): bool => $e->jobClass === 'App\\Jobs\\Slow'
        && $e->p95Ms === 5_000
        && $e->thresholdMs === 1_000);
});

it('skips slow_p95 for classes without a configured threshold', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.slow_p95.enabled', true);
    config()->set('queue-insights.alerts.rules.slow_p95.class_threshold_ms', []);
    config()->set('queue-insights.alerts.rules.failure_rate.enabled', false);

    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('classes'), Date::now()
        ->getTimestamp(), 'App\\Jobs\\Anything']);
    foreach (range(1, 20) as $_) {
        $redis->command('rpush', [KeyPrefix::make('duration:samples:App\\Jobs\\Anything'), '99999']);
    }

    Event::fake([JobClassP95Exceeded::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(JobClassP95Exceeded::class);
});

it('IssueDetector::detectAll surfaces failure_rate issues', function (): void {
    noopSnapshotConfig();
    config()->set('queue-insights.alerts.rules.failure_rate.min_jobs', 10);
    config()->set('queue-insights.alerts.rules.failure_rate.ratio', 0.10);

    seedClass('App\\Jobs\\Flaky', processed: 80, failed: 20);

    $detector = resolve(IssueDetector::class);
    $issues = $detector->detectAll();

    $rules = array_map(fn (Issue $i): string => $i->rule, $issues);
    expect($rules)->toContain('failure_rate');
});
