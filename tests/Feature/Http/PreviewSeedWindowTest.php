<?php declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Workbench\App\Support\PreviewSeeder;

/**
 * The preview seeder rewrites the whole fixture set — ~3,800 Redis writes.
 * Without the window that ran on every request, which on the deployed demo
 * (a managed Redis a couple of milliseconds away) was the entire page-load
 * budget. These cover the window's contract, which the rest of the suite
 * cannot: gating is off under test unless a window is set explicitly.
 */
beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights-preview.seed_window_seconds', 120);
});

function previewKeyCount(): int
{
    $keys = Redis::connection()->command('keys', ['*qmpreview:*']);

    return is_array($keys) ? count($keys) : 0;
}

it('seeds on the first request of a window', function (): void {
    (new PreviewSeeder())->seed();

    expect(previewKeyCount())->toBeGreaterThan(100);
});

it('skips the reseed for a later request in the same window', function (): void {
    (new PreviewSeeder())->seed();

    // A marker the seeder never writes: it survives only if the second
    // seed() left the keyspace alone rather than flushing and rebuilding it.
    Redis::connection()->command('set', ['qmpreview:sentinel', 'survived']);

    (new PreviewSeeder())->seed();

    expect(Redis::connection()->command('get', ['qmpreview:sentinel']))->toBe('survived');
});

it('reseeds once the window has expired', function (): void {
    (new PreviewSeeder())->seed();
    Redis::connection()->command('set', ['qmpreview:sentinel', 'survived']);

    // Expiring the freshness key is what the passage of time does.
    Redis::connection()->command('del', ['{qmpreview-seed}-fresh']);

    (new PreviewSeeder())->seed();

    expect(Redis::connection()->command('exists', ['qmpreview:sentinel']))->toEqual(0);
});

it('reseeds after a seed that failed before publishing its window', function (): void {
    // A seed that dies partway leaves the lock behind but never publishes
    // freshness. Once the lock expires the next request must rebuild rather
    // than serve the half-written keyspace for the rest of the window.
    Redis::connection()->command('set', ['{qmpreview-seed}-lock', 'held-by-a-dead-request']);

    (new PreviewSeeder())->seed();
    expect(previewKeyCount())->toBe(0);

    Redis::connection()->command('del', ['{qmpreview-seed}-lock']);

    (new PreviewSeeder())->seed();
    expect(previewKeyCount())->toBeGreaterThan(100);
});

it("claims and seeds when the holder's lock expires while it waits", function (): void {
    // The recovery path: a seeder died mid-run, so its lock is still there
    // when the next request arrives. That request waits rather than
    // rendering a flushed keyspace, and once the dead lock expires it takes
    // over and rebuilds — inside the same request, not the one after.
    Redis::connection()->command('setex', ['{qmpreview-seed}-lock', 2, 'held-by-a-dead-request']);

    (new PreviewSeeder())->seed();

    expect(previewKeyCount())->toBeGreaterThan(100)
        ->and(Redis::connection()->command('exists', ['{qmpreview-seed}-fresh']))->toEqual(1);
});

it('refuses to publish a window it no longer owns', function (): void {
    // A seed that overran its lock TTL has already been replaced by another
    // request, which flushed the keyspace and is rebuilding it. The overrun
    // seeder must not announce that work as fresh, nor delete the lock the
    // replacement is holding.
    $seeder = new PreviewSeeder();
    $reflection = new ReflectionClass($seeder);

    $claim = $reflection->getMethod('attemptClaim');

    expect($claim->invoke($seeder))->toBe(1);

    Redis::connection()->command('set', ['{qmpreview-seed}-lock', 'taken-over-by-someone-else']);

    $publish = $reflection->getMethod('publishSeedWindow');
    $publish->invoke($seeder);

    expect(Redis::connection()->command('exists', ['{qmpreview-seed}-fresh']))->toEqual(0)
        ->and(Redis::connection()->command('get', ['{qmpreview-seed}-lock']))->toBe('taken-over-by-someone-else');
});

it('does not gate when no window is configured', function (): void {
    config()->set('queue-insights-preview.seed_window_seconds', 0);

    (new PreviewSeeder())->seed();
    Redis::connection()->command('set', ['qmpreview:sentinel', 'survived']);

    (new PreviewSeeder())->seed();

    expect(Redis::connection()->command('exists', ['qmpreview:sentinel']))->toEqual(0);
});
