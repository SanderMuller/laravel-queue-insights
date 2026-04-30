<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use SanderMuller\QueueInsights\Alerts\Detectors\BacklogGrowingDetector;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Dashboard\AlertRulesPanelBuilder;
use SanderMuller\QueueInsights\Events\BacklogGrowing;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
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
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.alerts.rules.snapshot_errored.enabled', false);
});

function backlogDriver(int $depth): QueueSnapshotDriver
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

/**
 * Seed a (ts, depth) series into the samples zset directly so detector
 * tests don't need to call the snapshot command N times to build a window.
 *
 * @param  list<array{0: int, 1: int}>  $samples
 */
function seedDepthSamples(string $connection, string $queue, array $samples): void
{
    $key = KeyPrefix::make("samples:depth:{$connection}:{$queue}");
    foreach ($samples as [$ts, $depth]) {
        R::raw('zadd', $key, $ts, "{$ts}:{$depth}");
    }
}

it('writes a depth sample on every snapshot tick (capped + ttl)', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => backlogDriver(42));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);

    Artisan::call('queue-insights:snapshot');

    $key = 'qmtest:samples:depth:sqsq:work';
    expect(R::int('zcard', $key))->toBe(1)
        ->and(R::int('ttl', $key))->toBeGreaterThan(7000)->toBeLessThanOrEqual(7200);

    // Member shape "ts:depth"
    $members = R::raw('zrange', $key, 0, -1);
    expect($members[0])->toMatch('/^\d+:42$/');
});

it('caps the samples zset at the most recent 30 entries', function (): void {
    $now = Date::now()
        ->getTimestamp();
    $samples = [];
    for ($i = 0; $i < 35; ++$i) {
        $samples[] = [$now - (35 - $i) * 60, 100 + $i];
    }

    seedDepthSamples('sqsq', 'work', $samples);

    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => backlogDriver(200));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);

    Artisan::call('queue-insights:snapshot');

    expect(R::int('zcard', 'qmtest:samples:depth:sqsq:work'))->toBe(30);
});

it('fires BacklogGrowing on a positive slope above min_slope_per_minute', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => backlogDriver(1100));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.backlog_growing.enabled', true);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_slope_per_minute', 50.0);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_samples', 5);

    // 10 samples spanning 9 minutes, depth ramping 100 → 1000 (≈ 100/min slope).
    $now = Date::now()
        ->getTimestamp();
    $samples = [];
    for ($i = 0; $i < 10; ++$i) {
        $samples[] = [$now - (9 - $i) * 60, 100 + ($i * 100)];
    }

    seedDepthSamples('sqsq', 'work', $samples);

    Event::fake([BacklogGrowing::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(BacklogGrowing::class, fn (BacklogGrowing $e): bool => $e->connection === 'sqsq'
        && $e->queue === 'work'
        && $e->slopePerMinute >= 50.0
        && $e->minSlopePerMinute === 50.0
        && $e->severity === 'warning');
});

it('does not fire on a flat or shrinking series', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => backlogDriver(50));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.backlog_growing.enabled', true);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_slope_per_minute', 10.0);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_samples', 5);

    $now = Date::now()
        ->getTimestamp();
    $samples = [];
    for ($i = 0; $i < 10; ++$i) {
        // Slow drain: 200 → 50.
        $samples[] = [$now - (9 - $i) * 60, 200 - ($i * 15)];
    }

    seedDepthSamples('sqsq', 'work', $samples);

    Event::fake([BacklogGrowing::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(BacklogGrowing::class);
});

it('does not fire below min_samples (warmup guard)', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => backlogDriver(2000));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.backlog_growing.enabled', true);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_slope_per_minute', 10.0);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_samples', 5);

    // Only 3 samples — well below min_samples. Steep slope, but should not fire.
    $now = Date::now()
        ->getTimestamp();
    seedDepthSamples('sqsq', 'work', [
        [$now - 120, 100],
        [$now - 60, 500],
        [$now, 1000],
    ]);

    Event::fake([BacklogGrowing::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(BacklogGrowing::class);
});

it('detector returns null when the rule is disabled', function (): void {
    config()->set('queue-insights.alerts.rules.backlog_growing.enabled', false);

    $now = Date::now()
        ->getTimestamp();
    $samples = [];
    for ($i = 0; $i < 10; ++$i) {
        $samples[] = [$now - (9 - $i) * 60, 100 + ($i * 100)];
    }

    seedDepthSamples('sqsq', 'work', $samples);

    expect(resolve(BacklogGrowingDetector::class)->detect('sqsq', 'work'))->toBeNull();
});

it('rejects malformed backlog_growing config', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'backlog_growing' => ['min_slope_per_minute' => -1],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'min_slope_per_minute')
        ->and(fn () => ConfigValidator::validateAlerts([
            'rules' => [
                'backlog_growing' => ['min_samples' => 0],
            ],
        ]))
        ->toThrow(QueueInsightsConfigException::class, 'min_samples');
});

it('AlertRulesPanelBuilder surfaces backlog_growing in the panel rules list', function (): void {
    config()->set('queue-insights.alerts.rules.backlog_growing.enabled', true);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_slope_per_minute', 75);
    config()->set('queue-insights.alerts.rules.backlog_growing.min_samples', 8);

    $panel = resolve(AlertRulesPanelBuilder::class)->build();

    $rule = collect($panel['rules'])->firstWhere('key', 'backlog_growing');
    expect($rule)->not->toBeNull();

    /** @var array{key: string, enabled: bool, severity: ?string, params: list<array{label: string, value: string}>} $rule */
    expect($rule['enabled'])->toBeTrue();

    $params = collect($rule['params'])->keyBy('label')->all();
    /** @var array{label: string, value: string} $slopeParam */
    $slopeParam = $params['min_slope_per_minute'];
    /** @var array{label: string, value: string} $samplesParam */
    $samplesParam = $params['min_samples'];
    expect($slopeParam['value'])->toBe('75')
        ->and($samplesParam['value'])->toBe('8');
});
