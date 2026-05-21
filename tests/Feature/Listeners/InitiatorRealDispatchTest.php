<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Tests\Support\InitiatorQueuedJob;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.initiator.enabled', true);
    config()->set('queue-insights.initiator.capture_call_site', true);
    config()->set('queue-insights.initiator.call_site_max_depth', 30);

    // A real (non-sync) queue connection so dispatch() pushes to the queue
    // and the JobQueued event fires — sync dispatch runs inline and never
    // fires JobQueued (spec §1.3).
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
});

/**
 * Read the single pending row's call_site. Exactly one job is queued per
 * test, so the first pending row is the one under test.
 */
function singlePendingCallSite(): ?string
{
    // Drain the queued job's uuid off the pending zset RecordJobQueued wrote.
    $uuids = R::raw('zrange', 'qmtest:pending-zset:redis:default', 0, -1);
    if (! is_array($uuids) || $uuids === []) {
        return null;
    }

    $uuid = $uuids[0];
    if (! is_string($uuid)) {
        return null;
    }

    $row = PendingJobsReader::findByUuid($uuid);

    return $row['call_site'] ?? null;
}

it('the dispatch() helper resolves to an application frame', function (): void {
    dispatch(new InitiatorQueuedJob());

    $callSite = singlePendingCallSite();

    // The dispatch() helper frame lives in the framework global helpers.php
    // (no class) and is skipped — the walk reaches THIS test file.
    expect($callSite)->toBeString()
        ->and($callSite)->toContain('InitiatorRealDispatchTest.php');
});

it('a bare Job::dispatch() statement resolves to an application frame', function (): void {
    InitiatorQueuedJob::dispatch();

    $callSite = singlePendingCallSite();

    expect($callSite)->toBeString()
        ->and($callSite)->toContain('InitiatorRealDispatchTest.php');
});

it('two real dispatch sites of the same job class resolve to two different call_site values', function (): void {
    dispatch(new InitiatorQueuedJob());
    $uuids = R::raw('zrange', 'qmtest:pending-zset:redis:default', 0, -1);
    $firstUuid = is_array($uuids) && isset($uuids[0]) && is_string($uuids[0]) ? $uuids[0] : '';

    InitiatorQueuedJob::dispatch();
    $uuids = R::raw('zrange', 'qmtest:pending-zset:redis:default', 0, -1);
    $secondUuid = '';
    if (is_array($uuids)) {
        foreach ($uuids as $u) {
            if (is_string($u) && $u !== $firstUuid) {
                $secondUuid = $u;
            }
        }
    }

    $siteA = (new InitiatorStore())->read($firstUuid)['call_site'];
    $siteB = (new InitiatorStore())->read($secondUuid)['call_site'];

    expect($siteA)->toBeString()
        ->and($siteB)->toBeString()
        ->and($siteA)->toContain('InitiatorRealDispatchTest.php')
        ->and($siteB)->toContain('InitiatorRealDispatchTest.php')
        ->and($siteA)->not->toBe($siteB);
});
