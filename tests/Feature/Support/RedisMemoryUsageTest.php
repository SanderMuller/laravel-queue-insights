<?php declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Support\RedisMemoryUsage;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    Cache::flush();
});

it('returns null when the feature is disabled', function (): void {
    config()->set('queue-insights.dashboard.redis_memory.enabled', false);

    R::raw('set', 'qm:testing:foo', 'bar');

    expect((new RedisMemoryUsage())->totalBytes())->toBeNull();
});

it('sums MEMORY USAGE across every key under the package prefix', function (): void {
    config()->set('queue-insights.dashboard.redis_memory.enabled', true);
    config()->set('queue-insights.dashboard.redis_memory.cache_ttl', 60);

    R::raw('set', 'qm:testing:a', str_repeat('x', 256));
    R::raw('set', 'qm:testing:b', str_repeat('y', 512));
    // Out-of-prefix key — must not be summed.
    R::raw('set', 'other:noise', str_repeat('z', 1024));

    $bytes = (new RedisMemoryUsage())->totalBytes();

    // Sum of MEMORY USAGE for two ~256B and ~512B strings ought to land
    // comfortably between the raw payload size (768) and an upper bound
    // covering Redis's per-entry overhead. Tight enough to catch a
    // missed key, loose enough to survive sampling variance.
    expect($bytes)->toBeInt()
        ->and($bytes)->toBeGreaterThan(700)
        ->and($bytes)->toBeLessThan(4096);
});

it('caches the computed total for the configured TTL', function (): void {
    config()->set('queue-insights.dashboard.redis_memory.enabled', true);
    config()->set('queue-insights.dashboard.redis_memory.cache_ttl', 60);

    R::raw('set', 'qm:testing:first', str_repeat('a', 128));

    $helper = new RedisMemoryUsage();
    $first = $helper->totalBytes();

    // Drop every key — a fresh compute() would return 0. The cached
    // call must still report the original total, proving cache served.
    RedisAvailability::flush();

    expect($helper->totalBytes())->toBe($first);
});

it('namespaces its cache key by connection + prefix so two contexts do not bleed', function (): void {
    config()->set('queue-insights.dashboard.redis_memory.enabled', true);

    config()->set('queue-insights.key_prefix', 'qm:tenant-a:');
    R::raw('set', 'qm:tenant-a:foo', str_repeat('a', 1024));
    $tenantA = (new RedisMemoryUsage())->totalBytes();

    config()->set('queue-insights.key_prefix', 'qm:tenant-b:');
    R::raw('set', 'qm:tenant-b:bar', str_repeat('b', 64));
    $tenantB = (new RedisMemoryUsage())->totalBytes();

    // Each prefix gets its own cache slot. Without namespacing, tenant
    // B would have returned tenant A's still-warm 1 KB total — bounding
    // tenantB well below tenantA's lower bound proves cache slots split.
    expect($tenantA)->toBeInt()->toBeGreaterThan(900)
        ->and($tenantB)->toBeInt()->toBeLessThan(900);
});

it('logs at warning level and returns null when compute throws', function (): void {
    config()->set('queue-insights.dashboard.redis_memory.enabled', true);
    // Pointing at an unconfigured connection makes the inner
    // `Redis::connection(...)` throw, which the helper must catch.
    config()->set('queue-insights.redis_connection', 'this-connection-does-not-exist');

    $logSpy = Log::spy();

    expect((new RedisMemoryUsage())->totalBytes())->toBeNull();

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'redis-memory-usage compute failed')
            && ($context['connection'] ?? null) === 'this-connection-does-not-exist'
            && isset($context['exception'])
            && isset($context['message']))
        ->once();
});
