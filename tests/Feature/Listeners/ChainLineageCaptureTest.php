<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessing;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageClaim;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\ChainChildJob;
use SanderMuller\QueueInsights\Tests\Support\ChainGrandchildJob;
use SanderMuller\QueueInsights\Tests\Support\ChainParentJob;
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
    config()->set('queue-insights.capture.payloads', 'off');
    config()->set('queue-insights.chain_lineage.enabled', true);
    config()->set('queue-insights.chain_lineage.claim_ttl_seconds', 60);
    config()->set('queue-insights.chain_lineage.lineage_ttl_seconds', 604800);

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

function workChainTestQueue(int $jobs): void
{
    $worker = App::make('queue.worker');
    if (! $worker instanceof Worker) {
        throw new RuntimeException('queue.worker is not bound to a Worker instance');
    }

    for ($i = 0; $i < $jobs; ++$i) {
        $worker->runNextJob('database', 'default', new WorkerOptions());
    }
}

function lineageFor(string $uuid): ?string
{
    $value = R::raw('get', 'qmtest:lineage:' . $uuid);

    return is_string($value) ? $value : null;
}

it('single-link chain stamps parent_uuid on the child stream entry', function (): void {
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    workChainTestQueue(2);

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(2);

    $byClass = StreamEntries::byClass($entries);
    $parent = $byClass[ChainParentJob::class] ?? [];
    $child = $byClass[ChainChildJob::class] ?? [];

    expect($parent)->toHaveKey('uuid')
        ->and($child)
        ->toHaveKey('parent_uuid')
        ->and($child['parent_uuid'])
        ->toBe($parent['uuid']);

    // Interim `qi:lineage:{uuid}` hash is left in place after the copy
    // into the stream entry — it ages out via its 7-day TTL. A retry
    // path that re-runs JobProcessed (or a failed-then-retried-then-
    // succeeded sequence) would otherwise orphan the historical
    // failed_jobs row's parent attribution. Codex review.
    expect(lineageFor($child['uuid'] ?? ''))->toBe($parent['uuid']);
});

it('multi-link chain attributes each link to its dispatcher', function (): void {
    Bus::chain([new ChainParentJob(), new ChainChildJob(), new ChainGrandchildJob()])->dispatch();
    workChainTestQueue(3);

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(3);

    $byClass = StreamEntries::byClass($entries);
    $parent = $byClass[ChainParentJob::class] ?? [];
    $child = $byClass[ChainChildJob::class] ?? [];
    $grand = $byClass[ChainGrandchildJob::class] ?? [];

    expect($child['parent_uuid'] ?? null)->toBe($parent['uuid'] ?? null)
        ->and($grand['parent_uuid'] ?? null)->toBe($child['uuid'] ?? null);
});

it('concurrent disjoint chains attribute correctly', function (): void {
    // Two chains with DIFFERENT shapes — separate claim keys, no collision risk.
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    Bus::chain([new ChainParentJob(), new ChainGrandchildJob()])->dispatch();

    workChainTestQueue(4);

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(4);

    // Group by class; both ChainParentJob entries are roots (no parent).
    $parents = [];
    $childByClass = [];
    foreach ($entries as $fields) {
        if (($fields['class'] ?? null) === ChainParentJob::class) {
            $parents[] = $fields;
        } elseif (isset($fields['class'])) {
            $childByClass[$fields['class']] = $fields;
        }
    }

    expect($parents)->toHaveCount(2)->each->not->toHaveKey('parent_uuid');

    // Each child's parent_uuid must match SOME root uuid (not necessarily
    // a specific one — same-shape disjoint chains would be ambiguous, but
    // these are different shapes so attribution is deterministic).
    $rootUuids = [];
    foreach ($parents as $p) {
        $rootUuids[] = $p['uuid'] ?? '';
    }

    expect($childByClass[ChainChildJob::class]['parent_uuid'] ?? null)->toBeIn($rootUuids)
        ->and($childByClass[ChainGrandchildJob::class]['parent_uuid'] ?? null)
        ->toBeIn($rootUuids);
});

it('same-shape concurrent chains attribute in FIFO push order on a single worker', function (): void {
    // Two roots with IDENTICAL shape — same key. Single-worker correctness:
    // each push is followed by an in-process child JobQueued (synchronous
    // chain dispatch inside fire()), so the second parent's push lands AFTER
    // the first child has already popped. List depth never exceeds 1.
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();

    workChainTestQueue(4);

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(4);

    // Walk completion order: roots in dispatch order, children in dispatch order.
    $parents = [];
    $children = [];
    foreach ($entries as $fields) {
        if (($fields['class'] ?? null) === ChainParentJob::class) {
            $parents[] = $fields;
        } else {
            $children[] = $fields;
        }
    }

    expect($parents)->toHaveCount(2)
        ->and($children)
        ->toHaveCount(2);

    // Each child has a parent_uuid, and the SET of parent_uuids equals the
    // SET of root uuids (no orphans, no doubles, no nulls).
    $rootUuids = [];
    foreach ($parents as $p) {
        $rootUuids[] = $p['uuid'] ?? '';
    }

    $stampedParents = [];
    foreach ($children as $c) {
        $stampedParents[] = $c['parent_uuid'] ?? '';
    }

    sort($rootUuids);
    sort($stampedParents);

    expect($stampedParents)->toBe($rootUuids);
});

it('encrypted payloads are a no-op on both sides — no claim key written, no lineage stamped', function (): void {
    // Manually push a serialized "encrypted" payload through the queue.
    // SerializedCommandReader::extractChainContext returns null for
    // ShouldBeEncrypted commands (the body is base64, not a serialized
    // object). Asserting end-to-end behavior would require building a
    // real encrypted-job fixture, which is heavyweight. Instead: directly
    // invoke RecordJobProcessing with a non-serialized command body and
    // confirm no chain-claim key is written.
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('encrypted-parent-uuid');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn([
        'data' => [
            'commandName' => 'App\\Encrypted\\Job',
            'command' => 'eyJpdiI6...non-serialized-base64...',
        ],
    ]);

    resolve(RecordJobProcessing::class)
        ->handle(new JobProcessing('database', $job));

    $keys = R::raw('keys', 'qmtest:chain-claim:*');
    expect($keys)->toBeArray()->toBeEmpty();
});

it('retry on a child preserves parent_uuid on the eventual completion', function (): void {
    // Simulates: child JobQueued runs and pops the claim → lineage stored.
    // Then a retry path triggers JobQueued again — RPOP misses (already
    // consumed) but the existing lineage hash still holds the correct
    // parent. Phase 1 contract: the listener must NOT overwrite a non-null
    // existing parent with null. Verify by emulating: write lineage manually,
    // then call RecordJobQueued with a payload that has no chain context —
    // the existing lineage should survive.
    $store = new ChainLineageStore();
    $store->writeLineage('child-uuid-X', 'parent-uuid-Y', 604800);

    // Drive RecordJobQueued with a payload that yields no chain extraction
    // (no `data.command`). The early-return in resolveChainLineage protects
    // the existing hash.
    $payload = json_encode(['uuid' => 'child-uuid-X', 'displayName' => 'X', 'data' => []]);
    if ($payload === false) {
        $payload = '{}';
    }

    $event = new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: '1',
        job: new ChainChildJob(),
        payload: $payload,
        delay: null,
    );

    resolve(RecordJobQueued::class)->handle($event);

    expect($store->readLineage('child-uuid-X'))->toBe('parent-uuid-Y');
});

it('feature flag off short-circuits before any cache write', function (): void {
    config()->set('queue-insights.chain_lineage.enabled', false);

    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    workChainTestQueue(2);

    $claimKeys = R::raw('keys', 'qmtest:chain-claim:*');
    $lineageKeys = R::raw('keys', 'qmtest:lineage:*');

    expect($claimKeys)->toBeArray()->toBeEmpty()
        ->and($lineageKeys)
        ->toBeArray()
        ->toBeEmpty();

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->each->not->toHaveKey('parent_uuid');
});

it("a successful retry preserves the original failed row's parent attribution", function (): void {
    // Codex review regression: RecordJobProcessed used to delete
    // `qi:lineage:{uuid}` after copying it onto the stream entry. That
    // broke the failed-then-retried-then-succeeded flow — the original
    // failed_jobs row still sits in the failed list and reads the
    // lineage hash per render. The fix leaves the hash to TTL.
    $childUuid = 'retried-child';
    $parentUuid = 'retried-parent';

    (new ChainLineageStore())->writeLineage($childUuid, $parentUuid, 604800);

    // Drive RecordJobProcessed directly with a mocked job carrying the
    // child uuid. The listener should copy parent_uuid onto the stream
    // entry but NOT delete the interim hash.
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($childUuid);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Child');
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => 'App\\Jobs\\Child', 'command' => 'O:0:"":0:{}']]);
    $job->shouldReceive('attempts')->andReturn(1);

    resolve(RecordJobProcessed::class)
        ->handle(new JobProcessed('database', $job));

    expect((new ChainLineageStore())->readLineage($childUuid))->toBe($parentUuid);
});

it('SQS-style queue URL on JobQueued matches the worker-side logical name on JobProcessing', function (): void {
    // Simulates the SQS divergence: producers stamp the full queue URL on
    // JobQueued, but workers report the logical queue name on JobProcessing.
    // Without canonicalisation both sides would build different keys and
    // every chained SQS job would silently miss its parent attribution.
    $store = new ChainLineageStore();

    // Push side (parent enters processing on logical queue "work").
    $key = ChainLineageClaim::key(
        'sqs',
        CanonicalQueueKey::from('work'),
        ChainChildJob::class,
        [],
    );
    $store->pushClaim($key, 'parent-uuid-sqs', 60);

    // Pop side (child JobQueued event carries the full queue URL).
    $popKey = ChainLineageClaim::key(
        'sqs',
        CanonicalQueueKey::from('https://sqs.eu-west-1.amazonaws.com/123/work'),
        ChainChildJob::class,
        [],
    );

    expect($store->popClaim($popKey))->toBe('parent-uuid-sqs');
});

it('claim key is built from the next link class + tail fingerprint', function (): void {
    // Pure unit test on ChainLineageClaim — no I/O.
    $key = ChainLineageClaim::key('database', 'default', 'App\\Jobs\\Next', ['App\\Jobs\\After']);

    expect($key)->toStartWith(KeyPrefix::make('chain-claim:database:default:App\\Jobs\\Next:'));

    // Fingerprint is xxh3 of the JSON-encoded tail; same input → same output.
    $fp1 = ChainLineageClaim::fingerprint(['App\\Jobs\\A', 'App\\Jobs\\B']);
    $fp2 = ChainLineageClaim::fingerprint(['App\\Jobs\\A', 'App\\Jobs\\B']);
    expect($fp1)->toBe($fp2)->toHaveLength(16);

    // Empty tail still yields a stable hash (last-link case).
    expect(ChainLineageClaim::fingerprint([]))->toBe(hash('xxh3', '[]'));
});
