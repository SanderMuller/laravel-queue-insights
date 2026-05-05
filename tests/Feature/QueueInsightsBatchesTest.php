<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SanderMuller\QueueInsights\QueueInsights;
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

    // Replicate Laravel's default batches.stub so DatabaseBatchRepository
    // (the bus.batching.driver default) can read seeded fixture rows.
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('job_batches');
});

/**
 * Seed a batch row in the `job_batches` table — matches what
 * DatabaseBatchRepository::store does internally so `Bus::findBatch()` can
 * hydrate the row back into a `Batch` value object.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedBatch(string $id, array $overrides = []): void
{
    DB::table('job_batches')->insert(array_merge([
        'id' => $id,
        'name' => 'ImportCustomers',
        'total_jobs' => 3,
        'pending_jobs' => 1,
        'failed_jobs' => 1,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => Date::now()
            ->getTimestamp() - 60,
        'finished_at' => null,
    ], $overrides));
}

/**
 * Seed our Redis batch-tracking keys for a batchId, mirroring what
 * `RecordJobQueued::writeBatchTracking` would write at runtime.
 *
 * @param  list<string>  $uuids
 */
function seedBatchIndex(string $batchId, array $uuids, int $score): void
{
    R::raw('zadd', 'qmtest:batches:index', $score, $batchId);
    if ($uuids !== []) {
        $args = array_merge(['qmtest:batch:' . $batchId . ':uuids'], $uuids);
        R::raw('rpush', ...$args);
    }
}

it('recentBatches returns batches ordered by index score, newest first', function (): void {
    seedBatch('batch-old', ['name' => 'Old']);
    seedBatch('batch-mid', ['name' => 'Mid']);
    seedBatch('batch-new', ['name' => 'New']);

    seedBatchIndex('batch-old', [], Date::now()
        ->getTimestamp() - 300);
    seedBatchIndex('batch-mid', [], Date::now()
        ->getTimestamp() - 60);
    seedBatchIndex('batch-new', [], Date::now()
        ->getTimestamp());

    $rows = (new QueueInsights())->recentBatches();

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'id'))->toBe(['batch-new', 'batch-mid', 'batch-old'])
        ->and($rows[0]['name'])->toBe('New')
        ->and($rows[0]['total_jobs'])->toBe(3)
        ->and($rows[0]['pending_jobs'])->toBe(1)
        ->and($rows[0]['failed_jobs'])->toBe(1)
        ->and($rows[0]['processed_jobs'])->toBe(2);
});

it('recentBatches skips index entries whose Bus::findBatch row is missing', function (): void {
    seedBatch('batch-present');

    seedBatchIndex('batch-present', [], Date::now()
        ->getTimestamp() - 30);
    // Index entry without a corresponding job_batches row — typical when
    // Laravel's BatchRepository TTL aged the row out before our index TTL.
    seedBatchIndex('batch-ghost', [], Date::now()
        ->getTimestamp());

    $rows = (new QueueInsights())->recentBatches();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('batch-present');
});

it('batchDetail returns the joined batch + uuid list in enqueue order', function (): void {
    seedBatch('batch-detail');
    seedBatchIndex('batch-detail', ['uuid-a', 'uuid-b', 'uuid-c'], Date::now()
        ->getTimestamp());

    $detail = (new QueueInsights())->batchDetail('batch-detail');

    expect($detail)->not->toBeNull();

    if ($detail === null) {
        return;
    }

    expect($detail['id'])->toBe('batch-detail')
        ->and($detail['name'])->toBe('ImportCustomers')
        ->and($detail['total_jobs'])->toBe(3)
        ->and($detail['uuids'])->toBe(['uuid-a', 'uuid-b', 'uuid-c'])
        ->and($detail['progress'])->toBe(67); // (3 - 1) / 3 ≈ 67%
});

it('batchDetail returns null when the batch row has been deleted upstream', function (): void {
    // Index has the id but Bus::findBatch returns null — same aged-out flow
    // as the recentBatches test, but for the single-batch endpoint.
    seedBatchIndex('batch-gone', ['uuid-a'], Date::now()
        ->getTimestamp());

    $detail = (new QueueInsights())->batchDetail('batch-gone');

    expect($detail)->toBeNull();
});

it('recentBatches honours batches.max_per_query as an upper bound', function (): void {
    config()->set('queue-insights.batches.max_per_query', 2);

    seedBatch('batch-1');
    seedBatch('batch-2');
    seedBatch('batch-3');

    seedBatchIndex('batch-1', [], Date::now()
        ->getTimestamp() - 300);
    seedBatchIndex('batch-2', [], Date::now()
        ->getTimestamp() - 200);
    seedBatchIndex('batch-3', [], Date::now()
        ->getTimestamp() - 100);

    $rows = (new QueueInsights())->recentBatches(50);

    // The reader caps the requested limit at max_per_query, so the result
    // is truncated even when the caller asks for more.
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'id'))->toBe(['batch-3', 'batch-2']);
});

it('recentBatches returns an empty list when no batches are tracked', function (): void {
    expect((new QueueInsights())->recentBatches())
        ->toBeEmpty();
});

/**
 * Seed the per-connection batches index + :connection pointer for v2-gap
 * scoped reads. Mirrors what `RecordJobQueued::writeBatchTracking`'s
 * BatchClaimConnection.lua call writes at runtime.
 *
 * @param  list<string>  $uuids
 */
function seedScopedBatchIndex(string $batchId, string $connection, array $uuids, int $score): void
{
    seedBatchIndex($batchId, $uuids, $score);
    R::raw('zadd', 'qmtest:batches:index:' . $connection, $score, $batchId);
    R::raw('set', 'qmtest:batch:' . $batchId . ':connection', $connection);
}

it('recentBatches under scope reads only the per-connection index', function (): void {
    seedBatch('batch-redis');
    seedBatch('batch-sqs');

    seedScopedBatchIndex('batch-redis', 'redis', [], Date::now()->getTimestamp() - 60);
    seedScopedBatchIndex('batch-sqs', 'sqs', [], Date::now()->getTimestamp());

    $redisRows = (new QueueInsights())->recentBatches(50, 'redis');
    $sqsRows = (new QueueInsights())->recentBatches(50, 'sqs');

    expect(array_column($redisRows, 'id'))->toBe(['batch-redis'])
        ->and(array_column($sqsRows, 'id'))->toBe(['batch-sqs']);
});

it('recentBatches without scope still reads the aggregate index', function (): void {
    seedBatch('batch-redis');
    seedBatch('batch-sqs');

    seedScopedBatchIndex('batch-redis', 'redis', [], Date::now()->getTimestamp() - 60);
    seedScopedBatchIndex('batch-sqs', 'sqs', [], Date::now()->getTimestamp());

    $rows = (new QueueInsights())->recentBatches();

    expect(array_column($rows, 'id'))->toBe(['batch-sqs', 'batch-redis']);
});

it('batchDetail under scope returns null when the :connection pointer mismatches', function (): void {
    seedBatch('batch-redis-only');
    seedScopedBatchIndex('batch-redis-only', 'redis', ['uuid-a'], Date::now()->getTimestamp());

    $detail = (new QueueInsights())->batchDetail('batch-redis-only', 'sqs');

    expect($detail)->toBeNull();
});

it('batchDetail under scope returns the batch when the :connection pointer matches', function (): void {
    seedBatch('batch-redis-match');
    seedScopedBatchIndex('batch-redis-match', 'redis', ['uuid-a'], Date::now()->getTimestamp());

    $detail = (new QueueInsights())->batchDetail('batch-redis-match', 'redis');

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['id'])->toBe('batch-redis-match')
        ->and($detail['uuids'])->toBe(['uuid-a']);
});

it('batchDetail under scope passes through when the pointer is missing AND no per-connection roster claims the batch (truly-legacy)', function (): void {
    // Pre-existing data written before the v2-gap upgrade — neither the
    // pointer nor a per-connection roster has the batch, so the legacy
    // passthrough applies and the batch stays readable from any scope.
    seedBatch('batch-legacy');
    seedBatchIndex('batch-legacy', ['uuid-a'], Date::now()->getTimestamp());

    $detail = (new QueueInsights())->batchDetail('batch-legacy', 'redis');

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['uuids'])->toBe(['uuid-a']);
});

it('batchDetail under scope rejects when the pointer aged out but the batch is still in another connection roster', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);

    // Pointer absent but the per-connection roster still has the batch
    // under sqs. A redis-scoped read must NOT pass through the legacy
    // gate — sqs still owns this batch via its roster claim.
    seedBatch('batch-aged-pointer');
    seedBatchIndex('batch-aged-pointer', ['uuid-x'], Date::now()->getTimestamp());
    R::raw('zadd', 'qmtest:batches:index:sqs', Date::now()->getTimestamp(), 'batch-aged-pointer');

    $detail = (new QueueInsights())->batchDetail('batch-aged-pointer', 'redis');

    expect($detail)->toBeNull();
});

it('batchDetail under scope drops uuids whose batch-uuid-conn side-key mismatches', function (): void {
    seedBatch('batch-mixed');
    seedScopedBatchIndex('batch-mixed', 'redis', ['uuid-r', 'uuid-s', 'uuid-orphan'], Date::now()->getTimestamp());

    // uuid-r → redis (matches scope), uuid-s → sqs (cross-connection,
    // should drop), uuid-orphan has no side-key so it passes through
    // (legacy batches + members past batches.ttl_seconds).
    R::raw('set', 'qmtest:batch-uuid-conn:uuid-r', 'redis');
    R::raw('set', 'qmtest:batch-uuid-conn:uuid-s', 'sqs');

    $detail = (new QueueInsights())->batchDetail('batch-mixed', 'redis');

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['uuids'])->toBe(['uuid-r', 'uuid-orphan']);
});

it('batchDetail under scope still filters uuids after a member has been processed (side-key outlives pending hash)', function (): void {
    // The pending hash gets deleted on JobProcessed/JobFailed. The
    // dedicated side-key has a longer TTL so the scope filter keeps
    // working long after members have run.
    seedBatch('batch-after-run');
    seedScopedBatchIndex('batch-after-run', 'redis', ['uuid-redis', 'uuid-sqs-done'], Date::now()->getTimestamp());

    R::raw('set', 'qmtest:batch-uuid-conn:uuid-redis', 'redis');
    R::raw('set', 'qmtest:batch-uuid-conn:uuid-sqs-done', 'sqs');
    // No pending hashes — both members have already run.

    $detail = (new QueueInsights())->batchDetail('batch-after-run', 'redis');

    expect($detail)->not->toBeNull();
    if ($detail === null) {
        return;
    }

    expect($detail['uuids'])->toBe(['uuid-redis']);
});
