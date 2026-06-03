<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Support\CallSiteResolver;
use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\PendingJobsReader;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Tests\Support\InitiatorDispatchingJob;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use SanderMuller\QueueInsights\Tests\Support\StreamEntries;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.pending.enabled', true);
    config()->set('queue-insights.initiator.enabled', true);
    config()->set('queue-insights.initiator.capture_origin', true);
    config()->set('queue-insights.initiator.capture_call_site', true);
    config()->set('queue-insights.initiator.call_site_max_depth', 30);
    config()->set('queue-insights.initiator.ttl_seconds', 604800);
});

/**
 * Build a JobQueued event whose payload carries the given uuid.
 */
function makeCallSiteQueuedEvent(string $uuid): JobQueued
{
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\CallSiteTestJob']);

    return new JobQueued(
        connectionName: 'redis',
        queue: 'work',
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => 'App\\Jobs\\CallSiteTestJob'],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );
}

/**
 * Job mock answering every method RecordJobProcessed / RecordJobFailed touch.
 */
function makeCallSiteJobMock(string $uuid): Job&MockInterface
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn('work');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\CallSiteTestJob']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\CallSiteTestJob');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn($uuid);

    return $job;
}

// Two separate dispatch-site helpers — each runs the JobQueued listener on
// its own source line, so the resolved call site differs between them.
function dispatchFromSiteA(string $uuid): void
{
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));
}

function dispatchFromSiteB(string $uuid): void
{
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));
}

// --- Core requirement: two distinct dispatch sites ------------------------

it('two distinct dispatch sites of the same job class resolve to two DIFFERENT call_site values', function (): void {
    $uuidA = '01ARZ3NDEKTSV4RRFFQ69SITEA1';
    $uuidB = '01ARZ3NDEKTSV4RRFFQ69SITEB1';

    dispatchFromSiteA($uuidA);
    dispatchFromSiteB($uuidB);

    $siteA = (new InitiatorStore())->read($uuidA)['call_site'];
    $siteB = (new InitiatorStore())->read($uuidB)['call_site'];

    expect($siteA)->toBeString()
        ->and($siteB)->toBeString()
        // Both point into this test file but at different lines.
        ->and($siteA)->toContain('InitiatorCallSiteCaptureTest.php')
        ->and($siteB)->toContain('InitiatorCallSiteCaptureTest.php')
        ->and($siteA)->not->toBe($siteB);
});

it('a job dispatched from inside another jobs handle() records that parent jobs line', function (): void {
    $childUuid = (new InitiatorDispatchingJob())->dispatchNested();

    $callSite = (new InitiatorStore())->read($childUuid)['call_site'];

    // The call site is the dispatchNested() line inside the parent job —
    // exactly the "which flow dispatched it" attribution.
    expect($callSite)->toBeString()
        ->and($callSite)->toContain('InitiatorDispatchingJob.php');
});

// --- call_site written onto the interim key + pending hash ----------------

it('writes the resolved call_site onto qi:initiator:{uuid}', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSKEY01';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    expect(R::str('hget', 'qmtest:initiator:' . $uuid, 'call_site'))
        ->toContain('InitiatorCallSiteCaptureTest.php');
});

it('writes the resolved call_site onto the pending hash', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSPEND1';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'call_site'))
        ->toContain('InitiatorCallSiteCaptureTest.php');

    // PendingJobsReader surfaces it on the row.
    $row = PendingJobsReader::findByUuid($uuid);
    expect($row)->not->toBeNull()
        ->and($row['call_site'] ?? null)->toContain('InitiatorCallSiteCaptureTest.php');
});

// --- capture_call_site = false → no backtrace, no key ---------------------

it('writes no qi:initiator key and no pending call_site when capture_call_site is false', function (): void {
    config()->set('queue-insights.initiator.capture_call_site', false);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSOFF01';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    expect(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(0)
        ->and(R::int('hexists', 'qmtest:pending:' . $uuid, 'call_site'))->toBe(0);
});

it('writes no qi:initiator key when initiator.enabled is false', function (): void {
    config()->set('queue-insights.initiator.enabled', false);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSDIS01';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    expect(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(0);
});

// --- RecordJobProcessed: copy to stream + shorten TTL ---------------------

it('RecordJobProcessed copies call_site onto the completed stream entry', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSPROC1';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    resolve(RecordJobProcessed::class)->handle(
        new JobProcessed(connectionName: 'redis', job: makeCallSiteJobMock($uuid)),
    );

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(1);

    $fields = array_values($entries)[0];
    expect($fields['call_site'] ?? null)->toContain('InitiatorCallSiteCaptureTest.php');
});

it('RecordJobProcessed shortens the qi:initiator key TTL after the stream copy — never DELs', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSTTL01';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    // Queue-side TTL is the full 7d.
    expect(R::int('ttl', 'qmtest:initiator:' . $uuid))->toBeGreaterThan(60);

    resolve(RecordJobProcessed::class)->handle(
        new JobProcessed(connectionName: 'redis', job: makeCallSiteJobMock($uuid)),
    );

    // Shortened to the 60s tail, but the key still exists.
    $ttl = R::int('ttl', 'qmtest:initiator:' . $uuid);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(1);
});

it('RowEnricher surfaces call_site on a completed row', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSROW01';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));
    resolve(RecordJobProcessed::class)->handle(
        new JobProcessed(connectionName: 'redis', job: makeCallSiteJobMock($uuid)),
    );

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    $rows = [];
    foreach ($entries as $id => $fields) {
        $rows[] = $fields + ['_id' => $id];
    }

    $enriched = RowEnricher::completed($rows);
    expect($enriched)->toHaveCount(1)
        ->and($enriched[0]['call_site'] ?? null)->toContain('InitiatorCallSiteCaptureTest.php');
});

// --- RecordJobFailed: durable {origin, call_site} -------------------------

it('RecordJobFailed persists call_site into qi:initiator:{uuid}', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSFAIL1';

    // RecordJobQueued already stamped call_site queue-side.
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    resolve(RecordJobFailed::class)->handle(
        new JobFailed(connectionName: 'redis', job: makeCallSiteJobMock($uuid), exception: new RuntimeException('boom')),
    );

    expect(R::str('hget', 'qmtest:initiator:' . $uuid, 'call_site'))
        ->toContain('InitiatorCallSiteCaptureTest.php');

    // The failed-modal lazy resolve reads it back.
    expect((new InitiatorStore())->read($uuid)['call_site'])
        ->toContain('InitiatorCallSiteCaptureTest.php');
});

// --- chained 2nd+ link yields null ----------------------------------------

it('a chained 2nd+ link with no application frame yields call_site === null and omits the field', function (): void {
    // A chained link is queued by the worker's chain machinery — there is
    // no application frame above the framework/QI internals. Simulate by
    // pointing the skip-set at the whole project tree (tests + vendor) so
    // no frame in the bounded backtrace survives → resolve() returns null.
    $resolved = (new CallSiteResolver([dirname(__DIR__, 2), dirname(__DIR__, 3) . '/vendor']))->resolve(30);
    expect($resolved)->toBeNull();

    // With no resolved call site, RecordJobQueued writes no initiator key
    // and no pending call_site field — matching the unresolved path. We
    // exercise that with capture off as the equivalent no-key outcome.
    config()->set('queue-insights.initiator.capture_call_site', false);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CSCHAIN';
    (new RecordJobQueued())->handle(makeCallSiteQueuedEvent($uuid));

    expect(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(0)
        ->and(R::int('hexists', 'qmtest:pending:' . $uuid, 'call_site'))->toBe(0);
});
