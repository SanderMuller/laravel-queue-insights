<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Listeners\RecordJobFailed;
use SanderMuller\QueueInsights\Listeners\RecordJobProcessed;
use SanderMuller\QueueInsights\Tests\Support\ChainChildJob;
use SanderMuller\QueueInsights\Tests\Support\ChainParentJob;
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
    config()->set('queue-insights.batches.ttl_seconds', 604800);
    config()->set('queue-insights.pending.enabled', false);

    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->nullable();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('failed_jobs');
});

/**
 * @param  array<string, mixed>  $data
 */
function makeBatchJobMock(string $uuid, array $data = []): Job&MockInterface
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\BatchedJob', 'data' => $data]);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\BatchedJob');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getJobId')->andReturn($uuid);

    return $job;
}

it('RecordJobProcessed writes uuid → completed-stream-id mapping', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PROCBATCH';

    $event = new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    $streamId = R::str('get', 'qmtest:uuid-completed:' . $uuid);
    expect($streamId)->not->toBeNull();
    // Redis stream ids look like `<ms-ts>-<seq>`.
    expect($streamId)->toMatch('/^\d+-\d+$/');
});

it('RecordJobProcessed skips uuid → completed mapping only when both batches and chain_lineage are off', function (): void {
    // Chain lineage also reads from this index for the `↰ From`
    // click-through, so the listener now writes it when EITHER batches
    // OR chain_lineage is enabled. Both off → skipped.
    config()->set('queue-insights.batches.enabled', false);
    config()->set('queue-insights.chain_lineage.enabled', false);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69PROCOFF';

    $event = new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:uuid-completed:' . $uuid))->toBe(0);
});

it('RecordJobProcessed writes uuid → completed mapping when only chain_lineage is on', function (): void {
    config()->set('queue-insights.batches.enabled', false);
    config()->set('queue-insights.chain_lineage.enabled', true);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69CHAINONLY';

    $event = new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid));
    resolve(RecordJobProcessed::class)->handle($event);

    expect(R::int('exists', 'qmtest:uuid-completed:' . $uuid))->toBe(1);
});

it('keeps the uuid → completed index one hour for a job no view links to, and no class index', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69PLAINJOB';

    resolve(RecordJobProcessed::class)->handle(new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid)));

    expect(R::int('ttl', 'qmtest:uuid-completed:' . $uuid))->toBeGreaterThan(0)->toBeLessThanOrEqual(3600)
        ->and(R::int('exists', 'qmtest:class:' . $uuid))->toBe(0);
});

it('keeps the uuid → completed index for the full TTL for a batch member', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69BATCHMEMBR';

    resolve(RecordJobProcessed::class)->handle(new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid, ['batchId' => 'batch-1'])));

    expect(R::int('ttl', 'qmtest:uuid-completed:' . $uuid))->toBeGreaterThan(3600);
});

it('keeps the uuid → completed index for the full TTL for a job a scheduled task dispatched', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69SCHEDULED';
    Context::addHidden('qi_origin', 'schedule:task-a');

    resolve(RecordJobProcessed::class)->handle(new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid)));

    expect(R::int('ttl', 'qmtest:uuid-completed:' . $uuid))->toBeGreaterThan(3600);
});

it('keeps the uuid → completed index for the full TTL when origin capture is off', function (): void {
    config()->set('queue-insights.initiator.capture_origin', false);
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOORIGIN';

    resolve(RecordJobProcessed::class)->handle(new JobProcessed(connectionName: 'redis', job: makeBatchJobMock($uuid)));

    expect(R::int('ttl', 'qmtest:uuid-completed:' . $uuid))->toBeGreaterThan(3600);
});

it('RecordJobFailed writes no class index for a job without a chained tail', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILPLAIN';

    resolve(RecordJobFailed::class)->handle(new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid), exception: new RuntimeException('boom')));

    expect(R::int('exists', 'qmtest:class:' . $uuid))->toBe(0);
});

it('RecordJobFailed writes the class index for a failed job with a chained tail', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILCHAIN';
    $command = (new ChainParentJob())->chain([new ChainChildJob()]);

    resolve(RecordJobFailed::class)->handle(new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid, ['command' => serialize($command)]), exception: new RuntimeException('boom')));

    expect(R::str('get', 'qmtest:class:' . $uuid))->toBe('App\\Jobs\\BatchedJob');
});

it('RecordJobFailed writes uuid → failed_jobs-id mapping when the row exists', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILBATCH';

    $rowId = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => 'boom',
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    $event = new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    expect(R::str('get', 'qmtest:uuid-failed:' . $uuid))->toBe((string) $rowId);
});

it('RecordJobFailed silently skips when no failed_jobs row matches the uuid', function (): void {
    // Custom failed-job providers (or the default config with the row not yet
    // committed) won't have a matching id. Listener must not break the failure
    // path — it simply doesn't write the batch index entry.
    $uuid = '01ARZ3NDEKTSV4RRFFQ69NOFAILROW';

    $event = new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    expect(R::int('exists', 'qmtest:uuid-failed:' . $uuid))->toBe(0);
});

it('RecordJobFailed indexes the most recent failed_jobs row when a uuid has retried then failed again', function (): void {
    // DatabaseUuidFailedJobProvider inserts a fresh row on every JobFailed,
    // so a uuid that was retried and failed again has multiple rows. We must
    // index the just-inserted (highest-id) row — otherwise the batch-detail
    // click opens the older, stale failure.
    $uuid = '01ARZ3NDEKTSV4RRFFQ69RETRYFAIL';

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => 'first failure',
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    $newestId = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => 'second failure',
        'failed_at' => '2026-04-24 12:05:00',
    ]);

    $event = new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    expect(R::str('get', 'qmtest:uuid-failed:' . $uuid))->toBe((string) $newestId);
});

it('RecordJobFailed skips uuid → failed mapping only when both batches and chain_lineage are off', function (): void {
    // Same dual-gate as the processed-side write — chain lineage reads
    // this index for the `↰ From` click-through, so the listener still
    // writes it with batches off as long as chain_lineage is on.
    config()->set('queue-insights.batches.enabled', false);
    config()->set('queue-insights.chain_lineage.enabled', false);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69FAILOFF';

    DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => 'boom',
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    $event = new JobFailed(connectionName: 'redis', job: makeBatchJobMock($uuid), exception: new RuntimeException('boom'));
    resolve(RecordJobFailed::class)->handle($event);

    expect(R::int('exists', 'qmtest:uuid-failed:' . $uuid))->toBe(0);
});
