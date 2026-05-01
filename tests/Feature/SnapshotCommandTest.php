<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
});

function driverStub(int $depth = 0, ?int $inFlight = null, ?int $delayed = null): QueueSnapshotDriver
{
    return new readonly class ($depth, $inFlight, $delayed) implements QueueSnapshotDriver {
        public function __construct(private int $d, private ?int $i, private ?int $del) {}

        public function depth(string $queue): int
        {
            return $this->d;
        }

        public function inFlight(string $queue): ?int
        {
            return $this->i;
        }

        public function delayed(string $queue): ?int
        {
            return $this->del;
        }

        public function canonicalKey(string $queue): string
        {
            return CanonicalQueueKey::from($queue);
        }
    };
}

function failingDriverStub(): QueueSnapshotDriver
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

it('writes depth + inflight + delayed history ZSETs with 48h EXPIRE and 24h trim, plus 90s live cache', function (): void {
    config()->set('queue.connections.sqsq', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsq', fn () => driverStub(42, 5, 7));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqsq', 'queue' => 'work'],
    ]);

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    expect(R::int('zcard', 'qmtest:depth:sqsq:work'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:inflight:sqsq:work'))->toBe(1)
        ->and(R::int('zcard', 'qmtest:delayed:sqsq:work'))->toBe(1)
        ->and(R::int('ttl', 'qmtest:depth:sqsq:work'))->toBeGreaterThan(86400)
        ->toBeLessThanOrEqual(172800)
        ->and(R::int('get', 'qmtest:live:depth:sqsq:work'))->toBe(42)
        ->and(R::int('get', 'qmtest:live:inflight:sqsq:work'))->toBe(5)
        ->and(R::int('get', 'qmtest:live:delayed:sqsq:work'))->toBe(7);

    $liveTtl = R::int('ttl', 'qmtest:live:depth:sqsq:work');
    expect($liveTtl)->toBeGreaterThan(60)
        ->toBeLessThanOrEqual(90);
});

it('skips live cache + history for a metric that the driver returns null for', function (): void {
    config()->set('queue.connections.nullish', ['driver' => 'redis']);
    config()->set('queue-insights.driver_overrides.nullish', fn () => driverStub(3));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'nullish', 'queue' => 'work'],
    ]);

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    expect(R::int('zcard', 'qmtest:depth:nullish:work'))->toBe(1)
        ->and(R::int('exists', 'qmtest:inflight:nullish:work'))->toBe(0)
        ->and(R::int('exists', 'qmtest:delayed:nullish:work'))->toBe(0)
        ->and(R::int('exists', 'qmtest:live:inflight:nullish:work'))->toBe(0)
        ->and(R::int('exists', 'qmtest:live:delayed:nullish:work'))->toBe(0);
});

it('does not collide connection-scoped keys when the same queue name is used on two connections', function (): void {
    config()->set('queue.connections.one', ['driver' => 'redis']);
    config()->set('queue.connections.two', ['driver' => 'redis']);
    config()->set('queue-insights.driver_overrides.one', fn () => driverStub(1, 0, 0));
    config()->set('queue-insights.driver_overrides.two', fn () => driverStub(99, 0, 0));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'one', 'queue' => 'default'],
        ['connection' => 'two', 'queue' => 'default'],
    ]);

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    expect(R::int('get', 'qmtest:live:depth:one:default'))->toBe(1)
        ->and(R::int('get', 'qmtest:live:depth:two:default'))->toBe(99);
});

it('records snapshot:error when a driver throws, and continues to the next queue', function (): void {
    config()->set('queue.connections.broken', ['driver' => 'redis']);
    config()->set('queue.connections.ok', ['driver' => 'redis']);
    config()->set('queue-insights.driver_overrides.broken', fn () => failingDriverStub());
    config()->set('queue-insights.driver_overrides.ok', fn () => driverStub(5, 0, 0));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'broken', 'queue' => 'bad'],
        ['connection' => 'ok', 'queue' => 'good'],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('queue-insights: snapshot failed', Mockery::any());

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    expect($r->command('get', ['qmtest:snapshot:error:broken:bad']))->toBe('boom')
        ->and(R::int('get', 'qmtest:live:depth:ok:good'))->toBe(5);
});

it('clears snapshot:error on a successful run', function (): void {
    config()->set('queue.connections.recovering', ['driver' => 'redis']);
    config()->set('queue-insights.driver_overrides.recovering', fn () => driverStub(4, 0, 0));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'recovering', 'queue' => 'q'],
    ]);

    Redis::connection('default')->command('setex', ['qmtest:snapshot:error:recovering:q', 600, 'old failure']);

    Artisan::call('queue-insights:snapshot');

    expect(R::int('exists', 'qmtest:snapshot:error:recovering:q'))->toBe(0);
});

it('produces the same canonical key regardless of URL vs name input (SQS equivalence)', function (): void {
    config()->set('queue.connections.sqsurl', ['driver' => 'sqs']);
    config()->set('queue.connections.sqsname', ['driver' => 'sqs']);
    config()->set('queue-insights.driver_overrides.sqsurl', fn () => driverStub(10));
    config()->set('queue-insights.driver_overrides.sqsname', fn () => driverStub(11));
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqsurl', 'queue' => 'https://sqs.eu-west-1.amazonaws.com/123/my-q'],
        ['connection' => 'sqsname', 'queue' => 'my-q'],
    ]);

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    // Both entries should normalize to queue key 'my-q'.
    expect(R::int('get', 'qmtest:live:depth:sqsurl:my-q'))->toBe(10);
    expect(R::int('get', 'qmtest:live:depth:sqsname:my-q'))->toBe(11);
});

it('prunes classes older than 30 days at the end of the run', function (): void {
    $r = Redis::connection('default');

    $now = Date::now()->getTimestamp();
    $r->command('zadd', ['qmtest:classes', $now - (31 * 86400), 'App\\Jobs\\Ancient']);
    $r->command('zadd', ['qmtest:classes', $now, 'App\\Jobs\\Fresh']);

    config()->set('queue-insights.snapshots', []);

    Artisan::call('queue-insights:snapshot');

    $members = $r->command('zrange', ['qmtest:classes', 0, -1]);

    expect($members)->toContain('App\\Jobs\\Fresh')->not->toContain('App\\Jobs\\Ancient');
});

it('falls back to NullSnapshotDriver with warning when queue connection is unknown', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'ghost', 'queue' => 'default'],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('queue-insights: unknown queue driver; using NullSnapshotDriver', Mockery::any());

    Artisan::call('queue-insights:snapshot');

    $r = Redis::connection('default');

    // NullSnapshotDriver writes depth=0, inflight/delayed=null → no inflight/delayed keys.
    expect(R::int('get', 'qmtest:live:depth:ghost:default'))->toBe(0);
    expect(R::int('exists', 'qmtest:live:inflight:ghost:default'))->toBe(0);
});
