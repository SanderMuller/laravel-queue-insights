<?php declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.batches.enabled', true);
    config()->set('queue-insights.batches.max_uuids_per_batch', 5000);
    config()->set('queue-insights.batches.ttl_seconds', 604800);
    // Pending listener writes are noisy for batch-only assertions and
    // unrelated to this file's contract — silence them.
    config()->set('queue-insights.pending.enabled', false);
});

/**
 * Build a JobQueued event whose payload carries `data.batchId`. The batchId
 * must live at `data.batchId` (not under `data.command`) for the listener to
 * read it without deserializing the encrypted command body — that's how
 * Laravel writes Batchable jobs.
 */
function makeBatchedQueuedEvent(
    string $uuid,
    ?string $batchId,
    string $connection = 'redis',
    string $queue = 'default',
    string $displayName = 'App\\Jobs\\ImportRow',
    bool $encryptedCommand = false,
): JobQueued {
    $data = ['commandName' => $displayName];
    if ($encryptedCommand) {
        // Mirrors the ShouldBeEncrypted shape — opaque blob, batchId still
        // plaintext alongside it.
        $data['command'] = '<<encrypted>>';
    } else {
        $data['command'] = 'O:0:"":0:{}';
    }

    if ($batchId !== null) {
        $data['batchId'] = $batchId;
    }

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => $displayName,
        'data' => $data,
    ]);

    return new JobQueued(
        connectionName: $connection,
        queue: $queue,
        id: 'driver-id-' . Str::random(8),
        job: (object) ['displayName' => $displayName],
        payload: $payload === false ? '' : $payload,
        delay: null,
    );
}

it('writes the index, uuids list, and reverse uuid lookup when a batchId is present', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69BATCH1';
    $batchId = 'batch-aaa';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, $batchId));

    expect(R::float('zscore', 'qmtest:batches:index', $batchId))->toBeGreaterThan(0.0);

    $uuids = R::raw('lrange', 'qmtest:batch:' . $batchId . ':uuids', 0, -1);
    expect($uuids)->toBe([$uuid])
        ->and(R::str('get', 'qmtest:batch:uuid:' . $uuid))
        ->toBe($batchId);
});

it('is a no-op when the payload has no batchId', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOBATCH';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, null));

    expect(R::int('exists', 'qmtest:batches:index'))->toBe(0)
        ->and(R::str('get', 'qmtest:batch:uuid:' . $uuid))
        ->toBeNull();
});

it('captures the batchId on ShouldBeEncrypted-style payloads (data.command opaque)', function (): void {
    // The encrypted-command codepath was the failure mode the spec calls out
    // — `data.command` is unreadable but `data.batchId` is plaintext, so the
    // listener must read it via json_decode without touching the command.
    $uuid = '01ARZ3NDEKTSV4RRFFQ69ENC';
    $batchId = 'batch-encrypted';

    (new RecordJobQueued())->handle(
        makeBatchedQueuedEvent($uuid, $batchId, encryptedCommand: true),
    );

    expect(R::str('get', 'qmtest:batch:uuid:' . $uuid))->toBe($batchId)
        ->and(R::raw('lrange', 'qmtest:batch:' . $batchId . ':uuids', 0, -1))
        ->toBe([$uuid]);
});

it('honours max_uuids_per_batch as a best-effort cap', function (): void {
    config()->set('queue-insights.batches.max_uuids_per_batch', 3);
    $batchId = 'batch-capped';

    foreach (range(0, 4) as $i) {
        (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-' . $i, $batchId));
    }

    // Best-effort cap: the listener checks LLEN before pushing, so the 4th
    // and 5th writes see len==3 and skip. The list stays at the cap.
    expect(R::int('llen', 'qmtest:batch:' . $batchId . ':uuids'))->toBe(3);
});

it('sets the configured TTL on the per-batch keys (and not on the index)', function (): void {
    config()->set('queue-insights.batches.ttl_seconds', 3600);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69TTL';
    $batchId = 'batch-ttl';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, $batchId));

    $listTtl = R::int('ttl', 'qmtest:batch:' . $batchId . ':uuids');
    $reverseTtl = R::int('ttl', 'qmtest:batch:uuid:' . $uuid);

    expect($listTtl)->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600)
        ->and($reverseTtl)
        ->toBeGreaterThan(3590)
        ->toBeLessThanOrEqual(3600);

    // Index has no whole-key TTL — score-based pruning is the eviction
    // mechanism (see writeBatchTracking()'s ZREMRANGEBYSCORE call).
    expect(R::int('ttl', 'qmtest:batches:index'))->toBe(-1);
});

it('is a no-op when batches.enabled is false', function (): void {
    config()->set('queue-insights.batches.enabled', false);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69OFF';
    $batchId = 'batch-off';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, $batchId));

    expect(R::int('exists', 'qmtest:batches:index'))->toBe(0)
        ->and(R::int('exists', 'qmtest:batch:' . $batchId . ':uuids'))
        ->toBe(0)
        ->and(R::str('get', 'qmtest:batch:uuid:' . $uuid))
        ->toBeNull();
});

it('preserves first-write-wins on the index score (ZADD NX)', function (): void {
    $batchId = 'batch-race';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-first', $batchId));
    $first = R::float('zscore', 'qmtest:batches:index', $batchId);

    // Wait long enough that the second ZADD's would-be score differs by at
    // least one second. ZADD NX must keep the original score.
    Sleep::sleep(1);

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-second', $batchId));
    $second = R::float('zscore', 'qmtest:batches:index', $batchId);

    expect($second)->toBe($first);
});

it('writes batch_id into the pending hash when pending tracking is enabled', function (): void {
    config()->set('queue-insights.pending.enabled', true);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PENDBATCH';
    $batchId = 'batch-pending-hash';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, $batchId));

    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'batch_id'))->toBe($batchId);
});

it('writes an empty batch_id field for non-batched pending jobs (stable shape)', function (): void {
    config()->set('queue-insights.pending.enabled', true);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PENDNOBAT';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent($uuid, null));

    // Empty string keeps the hash field shape stable so PendingJobsReader can
    // hydrate the field unconditionally without an HEXISTS round-trip.
    expect(R::str('hget', 'qmtest:pending:' . $uuid, 'batch_id'))
        ->toBeEmpty();
});

it('prunes index entries older than the TTL on each enqueue', function (): void {
    config()->set('queue-insights.batches.ttl_seconds', 60);

    // Seed a stale member directly so the next listener write is forced to
    // prune it via ZREMRANGEBYSCORE.
    $stale = (string) (Date::now()
        ->getTimestamp() - 3600);
    R::raw('zadd', 'qmtest:batches:index', $stale, 'stale-batch');

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-fresh', 'fresh-batch'));

    expect(R::float('zscore', 'qmtest:batches:index', 'stale-batch'))->toBe(0.0)
        ->and(R::float('zscore', 'qmtest:batches:index', 'fresh-batch'))
        ->toBeGreaterThan(0.0);
});

it('populates the per-connection batches roster and pointer on first write', function (): void {
    $batchId = 'batch-conn-redis';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-r1', $batchId, connection: 'redis'));

    expect(R::float('zscore', 'qmtest:batches:index:redis', $batchId))->toBeGreaterThan(0.0)
        ->and(R::str('get', 'qmtest:batch:' . $batchId . ':connection'))
        ->toBe('redis');
});

it('first-write-wins: a second connection cannot claim a batch already pointed at the first', function (): void {
    $batchId = 'batch-heterogeneous';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-r1', $batchId, connection: 'redis'));
    // Second JobQueued for the SAME batchId on a DIFFERENT connection — must
    // NOT land in qi:batches:index:sqs because the :connection pointer
    // already arbitrates redis as the winner.
    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-s1', $batchId, connection: 'sqs'));

    expect(R::float('zscore', 'qmtest:batches:index:redis', $batchId))->toBeGreaterThan(0.0)
        ->and(R::float('zscore', 'qmtest:batches:index:sqs', $batchId))
        ->toBe(0.0)
        ->and(R::str('get', 'qmtest:batch:' . $batchId . ':connection'))
        ->toBe('redis');
});

it('sets the configured TTL on the per-connection :connection pointer', function (): void {
    config()->set('queue-insights.batches.ttl_seconds', 1800);
    $batchId = 'batch-conn-ttl';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-r1', $batchId, connection: 'redis'));

    $ttl = R::int('ttl', 'qmtest:batch:' . $batchId . ':connection');
    expect($ttl)->toBeGreaterThan(1790)->toBeLessThanOrEqual(1800);
});

it('prunes per-connection roster entries older than the TTL on each enqueue', function (): void {
    config()->set('queue-insights.batches.ttl_seconds', 60);

    $stale = (string) (Date::now()->getTimestamp() - 3600);
    R::raw('zadd', 'qmtest:batches:index:redis', $stale, 'stale-batch');

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-fresh', 'fresh-batch-c', connection: 'redis'));

    expect(R::float('zscore', 'qmtest:batches:index:redis', 'stale-batch'))->toBe(0.0)
        ->and(R::float('zscore', 'qmtest:batches:index:redis', 'fresh-batch-c'))
        ->toBeGreaterThan(0.0);
});

it('skips per-connection write when the JobQueued connectionName is empty', function (): void {
    $batchId = 'batch-no-conn';

    (new RecordJobQueued())->handle(makeBatchedQueuedEvent('uuid-blank', $batchId, connection: ''));

    // Aggregate index still receives the batch (un-scoped path stays
    // load-bearing); per-connection index + pointer must NOT exist.
    expect(R::float('zscore', 'qmtest:batches:index', $batchId))->toBeGreaterThan(0.0)
        ->and(R::str('get', 'qmtest:batch:' . $batchId . ':connection'))
        ->toBeNull();
});
