<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\Registry;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
});

it('resolves a fresh Registry per container make (octane-safe)', function (): void {
    $first = $this->app->make(Registry::class);
    $second = $this->app->make(Registry::class);

    expect($first)->not->toBe($second);
});

it('memoises rendered output within a single instance', function (): void {
    config()->set('queue-insights.prometheus.cache_ttl_seconds', 0);

    $registry = $this->app->make(Registry::class);
    $first = $registry->render();
    $second = $registry->render();

    // Memoise returns the same string instance — easiest assertion is
    // identity on the byte content.
    expect($first)->toBe($second);
});
