<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\Detectors\ConnectionDriftDetector;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.connection_aliases', []);
    config()->set('queue-insights.horizon.autodiscover', false);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis-staging', 'queue' => 'premium-calculator'],
    ]);
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis'],
        'redis-staging' => ['driver' => 'redis'],
        'sqs' => ['driver' => 'sqs'],
    ]);
});

it('returns no issues when the rule is disabled', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', false);

    // Seed a drift signal that WOULD fire if the rule were on.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);

    expect(resolve(ConnectionDriftDetector::class)->detect())
        ->toBeEmpty();
});

it('returns no issues when no candidate connection has pending rows under a non-canonical name', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);

    // Pending row sits under the configured canonical — no drift.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis-staging:premium-calculator', 1, 'uuid-1']);

    expect(resolve(ConnectionDriftDetector::class)->detect())
        ->toBeEmpty();
});

it('flags drift when a non-canonical connection has pending rows for a configured queue', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 2, 'uuid-2']);

    $issues = resolve(ConnectionDriftDetector::class)->detect();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->rule)->toBe(ConnectionDriftDetector::RULE)
        ->and($issues[0]->connection)->toBe('redis')
        ->and($issues[0]->queue)->toBe('premium-calculator')
        ->and($issues[0]->context['non_canonical_connection'])->toBe('redis')
        ->and($issues[0]->context['canonical_connections'])->toBe(['redis-staging'])
        ->and($issues[0]->context['pending_count'])->toBe(2);
});

it('emits a single Issue with all canonicals listed when multiple connections share the queue', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);
    // Two configured canonicals serving the same queue name `default`.
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis-staging', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);
    // `redis` is neither of the canonicals — drift.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis:default', 1, 'uuid-1']);

    $issues = resolve(ConnectionDriftDetector::class)->detect();

    // ONE issue, not two — pre-fix the detector emitted one per canonical
    // pair, suggesting wrong alias targets.
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->connection)->toBe('redis')
        ->and($issues[0]->context['canonical_connections'])->toBe(['redis-staging', 'sqs'])
        ->and($issues[0]->description)->toContain('Multiple canonical connections are configured')
        ->and($issues[0]->description)->toContain("'redis-staging'")
        ->and($issues[0]->description)->toContain("'sqs'");
});

it('does not flag aliased connections (the alias collapses producer + worker)', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    // `redis` is mapped → `redis-staging`. ConfiguredQueueList::push canonicalises
    // the snapshot's `redis-staging` to itself; the candidate `redis` also
    // resolves to `redis-staging`, so it is filtered before the ZCARD probe.
    R::conn()->command('zadd', ['qmtest:pending-zset:redis-staging:premium-calculator', 1, 'uuid-1']);

    expect(resolve(ConnectionDriftDetector::class)->detect())
        ->toBeEmpty();
});

it('flags one issue per non-canonical connection × configured queue', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);
    R::conn()->command('zadd', ['qmtest:pending-zset:sqs:premium-calculator', 1, 'uuid-2']);

    $issues = resolve(ConnectionDriftDetector::class)->detect();

    expect($issues)->toHaveCount(2)
        ->and(collect($issues)->pluck('connection')->sort()->values()->all())
        ->toBe(['redis', 'sqs']);
});

it('skips probing when the host has no queue.connections defined', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);
    config()->set('queue.connections');

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);

    expect(resolve(ConnectionDriftDetector::class)->detect())
        ->toBeEmpty();
});

it('produces an operator-actionable description naming both connections + queue', function (): void {
    config()->set('queue-insights.alerts.rules.connection_drift.enabled', true);

    R::conn()->command('zadd', ['qmtest:pending-zset:redis:premium-calculator', 1, 'uuid-1']);

    $issue = resolve(ConnectionDriftDetector::class)->detect()[0];

    expect($issue->description)->toContain("'redis'")
        ->and($issue->description)->toContain("'redis-staging'")
        ->and($issue->description)->toContain("'premium-calculator'")
        ->and($issue->description)->toContain("'connection_aliases' => ['redis' => 'redis-staging']");
});
