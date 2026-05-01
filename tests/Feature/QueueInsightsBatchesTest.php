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
