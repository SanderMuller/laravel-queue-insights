<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Detectors\OldestPendingDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\StuckInFlightDetector;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Alerts\IssueDetector;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Events\OldestPendingAging;
use SanderMuller\QueueInsights\Events\QueueDepthExceeded;
use SanderMuller\QueueInsights\Events\QueueStalled;
use SanderMuller\QueueInsights\Events\SnapshotErrored;
use SanderMuller\QueueInsights\Events\StuckInFlight;
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

function quietDriver(int $depth = 0): QueueSnapshotDriver
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

function exploderDriver(): QueueSnapshotDriver
{
    return new class implements QueueSnapshotDriver {
        public function depth(string $queue): int
        {
            throw new RuntimeException('driver exploded');
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

it('fires QueueStalled when depth >= min_depth and no recent worker pickups', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(50));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.idle_seconds', 60);
    config()->set('queue-insights.alerts.rules.stalled.min_depth', 1);

    Event::fake([QueueStalled::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(QueueStalled::class, fn (QueueStalled $e): bool => $e->connection === 'sqsq'
        && $e->queue === 'work'
        && $e->depth === 50
        && $e->severity === 'critical');
});

it('does not fire QueueStalled when a worker pickup landed inside the idle window', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(50));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.stalled.idle_seconds', 60);

    // Simulate a recent pickup — score = now.
    Redis::connection('default')->command('zadd', [
        KeyPrefix::make('wait:sqsq:work'), Date::now()
            ->getTimestamp(), 'fresh-uuid',
    ]);

    Event::fake([QueueStalled::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(QueueStalled::class);
});

it('does not fire QueueStalled when depth is below min_depth', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.stalled.min_depth', 1);

    Event::fake([QueueStalled::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(QueueStalled::class);
});

it('fires OldestPendingAging when the oldest available_at is older than the threshold', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 60);
    // Depth-rule thresholds empty so depth detector returns null.
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    // Disable stalled to keep this test focused.
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    $oldAvailableAt = Date::now()
        ->getTimestamp() - 600;
    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), $oldAvailableAt, 'old-uuid']);
    $redis->command('hset', [KeyPrefix::make('pending:old-uuid'), 'class', 'App\\Jobs\\Slow']);

    Event::fake([OldestPendingAging::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(OldestPendingAging::class, fn (OldestPendingAging $e): bool => $e->oldestUuid === 'old-uuid'
        && $e->oldestClass === 'App\\Jobs\\Slow'
        && $e->ageSeconds >= 600
        && $e->thresholdSeconds === 60);
});

it('skips delayed (not-yet-due) entries when picking the oldest pending', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    $future = Date::now()
        ->getTimestamp() + 600;
    Redis::connection('default')->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), $future, 'delayed-uuid']);

    Event::fake([OldestPendingAging::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(OldestPendingAging::class);
});

it('auto-disables oldest_pending when pending.enabled = false and warns at boot', function (): void {
    config()->set('queue-insights.pending.enabled', false);
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 1);

    // Even with a stale entry, the detector must not fire when pending tracking is off.
    Redis::connection('default')->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), Date::now()
        ->getTimestamp() - 600, 'old-uuid']);

    Event::fake([OldestPendingAging::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(OldestPendingAging::class);
});

it('renders the oldest_pending wait as a human-readable duration, not raw seconds', function (): void {
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 600);

    // 6d 22h 40m of waiting. The description must humanise it; the raw
    // second count survives only in context as `age_seconds`.
    $now = 2_000_000_000;
    $availableAt = $now - 600_000;

    $detector = resolve(OldestPendingDetector::class);
    $issue = $detector->evaluate('sqsq', 'work', ['orphan-ish-uuid', $availableAt], null, $now);

    expect($issue)->not->toBeNull();
    assert($issue !== null);
    expect($issue->description)
        ->toContain('has been waiting 6d 22h.')
        ->not->toContain('600000s')
        ->and($issue->context['age_seconds'])->toBe(600_000);
});

it('renders the stuck_inflight runtime as a human-readable duration, not raw seconds', function (): void {
    config()->set('queue-insights.alerts.rules.stuck_inflight.seconds', 300);

    $now = 2_000_000_000;
    $startedAt = $now - 7_200;

    $detector = resolve(StuckInFlightDetector::class);
    $issue = $detector->evaluate('sqsq', 'work', ['stuck-uuid', $startedAt], null, $now);

    expect($issue)->not->toBeNull();
    assert($issue !== null);
    expect($issue->description)
        ->toContain('has been running 2h.')
        ->not->toContain('7200s')
        ->and($issue->context['age_seconds'])->toBe(7_200);
});

it('excludes orphaned pending-zset members older than the retention window', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.pending.ttl_seconds', 86400);

    // An orphan: its `available_at` is older than the 86400s retention
    // window, so the backing `pending:{uuid}` hash has long TTL'd out but
    // the zset member lingered (cleanup zrem missed it). A 6-day-old
    // member like the one staging hit — must NOT be picked as the head.
    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), Date::now()->getTimestamp() - 600_000, 'orphan-uuid']);

    Event::fake([OldestPendingAging::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(OldestPendingAging::class);
});

it('alerts on a real aging job queued behind an orphan (orphan does not mask it)', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.pending.ttl_seconds', 86400);

    $redis = Redis::connection('default');
    // Orphan (oldest score) + a real aging job inside the retention window.
    // The score-bounded query skips the orphan and returns the real one.
    $redis->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), Date::now()->getTimestamp() - 600_000, 'orphan-uuid']);
    $redis->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), Date::now()->getTimestamp() - 600, 'real-uuid']);
    $redis->command('hset', [KeyPrefix::make('pending:real-uuid'), 'class', 'App\\Jobs\\Slow']);

    Event::fake([OldestPendingAging::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(OldestPendingAging::class, fn (OldestPendingAging $e): bool => $e->oldestUuid === 'real-uuid'
        && $e->oldestClass === 'App\\Jobs\\Slow');
});

it('excludes orphaned inflight-zset members older than the retention window', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.stuck_inflight.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);
    config()->set('queue-insights.pending.ttl_seconds', 86400);

    // Worker SIGKILLed mid-job → RecordJobProcessed/Failed never ran, so
    // the inflight-zset member orphaned. `started_at` older than the
    // retention window — must not fire stuck_inflight forever.
    Redis::connection('default')->command('zadd', [KeyPrefix::make('inflight-zset:sqsq:work'), Date::now()->getTimestamp() - 600_000, 'orphan-uuid']);

    Event::fake([StuckInFlight::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(StuckInFlight::class);
});

it('fires StuckInFlight when oldest started_at exceeds the threshold', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.stuck_inflight.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    $startedAt = Date::now()
        ->getTimestamp() - 300;
    $redis = Redis::connection('default');
    $redis->command('zadd', [KeyPrefix::make('inflight-zset:sqsq:work'), $startedAt, 'stuck-uuid']);
    $redis->command('hset', [KeyPrefix::make('pending:stuck-uuid'), 'class', 'App\\Jobs\\LongRunner']);

    Event::fake([StuckInFlight::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(StuckInFlight::class, fn (StuckInFlight $e): bool => $e->oldestUuid === 'stuck-uuid'
        && $e->oldestClass === 'App\\Jobs\\LongRunner'
        && $e->ageSeconds >= 300
        && $e->thresholdSeconds === 60);
});

it('does not fire StuckInFlight when in-flight zset is empty', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.stuck_inflight.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    Event::fake([StuckInFlight::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertNotDispatched(StuckInFlight::class);
});

it('OldestPending Issue omits oldest_class from context when the pending hash has no class field', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.oldest_pending.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    $oldAvailableAt = Date::now()->getTimestamp() - 600;
    Redis::connection('default')->command('zadd', [KeyPrefix::make('pending-zset:sqsq:work'), $oldAvailableAt, 'class-less-uuid']);
    // No `hset pending:class-less-uuid class ...` — simulating a hash that
    // dropped its class field (TTL pruning) or was never written by a
    // foreign producer. The detector must still fire but the resulting
    // context must NOT carry an empty `oldest_class` slot.

    /** @var IssueDetector $detector */
    $detector = resolve(IssueDetector::class);
    $issues = $detector->detectForSnapshot('sqsq', 'work', 0);

    $oldest = array_values(array_filter($issues, fn (Issue $i): bool => $i->rule === 'oldest_pending'));
    expect($oldest)->toHaveCount(1)
        ->and(array_key_exists('oldest_class', $oldest[0]->context))->toBeFalse();
});

it('StuckInFlight Issue omits oldest_class from context when the pending hash has no class field', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(0));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.alerts.rules.stuck_inflight.seconds', 60);
    config()->set('queue-insights.alerts.rules.depth.thresholds', []);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    $startedAt = Date::now()->getTimestamp() - 300;
    Redis::connection('default')->command('zadd', [KeyPrefix::make('inflight-zset:sqsq:work'), $startedAt, 'class-less-uuid']);

    /** @var IssueDetector $detector */
    $detector = resolve(IssueDetector::class);
    $issues = $detector->detectForSnapshot('sqsq', 'work', 0);

    $stuck = array_values(array_filter($issues, fn (Issue $i): bool => $i->rule === 'stuck_inflight'));
    expect($stuck)->toHaveCount(1)
        ->and(array_key_exists('oldest_class', $stuck[0]->context))->toBeFalse();
});

it('fires SnapshotErrored when the driver throws (catch path)', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => exploderDriver());
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.stalled.enabled', false);

    Log::shouldReceive('warning')->zeroOrMoreTimes();

    Event::fake([SnapshotErrored::class]);

    Artisan::call('queue-insights:snapshot');

    Event::assertDispatched(SnapshotErrored::class, fn (SnapshotErrored $e): bool => $e->connection === 'sqsq'
        && $e->queue === 'work'
        && str_contains($e->errorMessage, 'driver exploded'));
});

it('cooldown for one rule does not suppress a different rule on the same queue', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(50));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    // Both depth and stalled fire on the same tick.
    config()->set('queue-insights.alerts.rules.depth.thresholds', [
        ['connection' => 'sqsq', 'queue' => 'work', 'depth' => 10, 'severity' => 'warning'],
    ]);
    config()->set('queue-insights.alerts.rules.stalled.idle_seconds', 60);
    config()->set('queue-insights.alerts.rules.stalled.min_depth', 1);

    Event::fake([QueueDepthExceeded::class, QueueStalled::class]);

    Artisan::call('queue-insights:snapshot');

    // Each rule fires under its own cooldown namespace.
    Event::assertDispatched(QueueDepthExceeded::class);
    Event::assertDispatched(QueueStalled::class);
});

it('reaches detector read path for SQS-style URL via canonical key', function (): void {
    $url = 'https://sqs.eu-west-1.amazonaws.com/123/work';
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => quietDriver(50));
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => $url]]);
    config()->set('queue-insights.alerts.rules.stalled.idle_seconds', 60);
    config()->set('queue-insights.alerts.rules.stalled.min_depth', 1);

    Event::fake([QueueStalled::class]);

    Artisan::call('queue-insights:snapshot');

    // Canonical key for the URL is `work` — detector should fire under that key,
    // not the raw URL.
    Event::assertDispatched(QueueStalled::class, fn (QueueStalled $e): bool => $e->queue === 'work');
});

it('IssueDetector::detectAll runs queue-scoped detectors against live state', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.snapshots', [['connection' => 'sqsq', 'queue' => 'work']]);
    config()->set('queue-insights.alerts.rules.stalled.idle_seconds', 60);
    config()->set('queue-insights.alerts.rules.stalled.min_depth', 1);

    $redis = Redis::connection('default');
    // Simulate a live snapshot with depth 75 but no recent pickups.
    $redis->command('setex', [KeyPrefix::make('live:depth:sqsq:work'), 90, '75']);

    $detector = resolve(IssueDetector::class);
    $issues = $detector->detectAll();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->rule)->toBe('stalled')
        ->and($issues[0]->severity)->toBe(AlertSeverity::Critical)
        ->and($issues[0]->queue)->toBe('work');
});
