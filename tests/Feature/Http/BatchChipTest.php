<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
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
    config()->set('queue-insights.snapshots', [['connection' => 'redis', 'queue' => 'work']]);

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

it('renders the batch chip on a completed row when uuid → batch_id reverse lookup hits', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69CHIPCMP';
    $batchId = 'batch-completed-chip';

    R::raw('set', 'qmtest:batch:uuid:' . $uuid, $batchId);

    R::raw('eval', "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1], 'connection', ARGV[2], 'queue', ARGV[3], 'duration_ms', ARGV[4], 'attempts', ARGV[5], 'processed_at', ARGV[6], 'uuid', ARGV[7])",
        1,
        'qmtest:completed',
        'App\\Jobs\\BatchedJob',
        'redis',
        'work',
        '15',
        '1',
        '2026-04-24T12:00:00+00:00',
        $uuid,
    );

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee($batchId)
        ->assertSeeHtml('openBatch');
});

it('does not render the chip on completed rows when batches.enabled is false', function (): void {
    config()->set('queue-insights.batches.enabled', false);

    $uuid = '01ARZ3NDEKTSV4RRFFQ69CHIPOFF';
    $batchId = 'batch-disabled';

    R::raw('set', 'qmtest:batch:uuid:' . $uuid, $batchId);
    R::raw('eval', "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1], 'connection', ARGV[2], 'queue', ARGV[3], 'duration_ms', ARGV[4], 'attempts', ARGV[5], 'processed_at', ARGV[6], 'uuid', ARGV[7])",
        1,
        'qmtest:completed',
        'App\\Jobs\\BatchedJob',
        'redis',
        'work',
        '15',
        '1',
        '2026-04-24T12:00:00+00:00',
        $uuid,
    );

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSee($batchId);
});

it('renders the batch chip on a failed row by reading data.batchId from the payload', function (): void {
    $batchId = 'batch-failed-chip';

    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-failed-chip',
        'connection' => 'redis',
        'queue' => 'work',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\BatchedJob',
            'data' => ['commandName' => 'App\\Jobs\\BatchedJob', 'command' => 'O:0:"":0:{}', 'batchId' => $batchId],
        ]),
        'exception' => "RuntimeException: boom\n#0 /app.php(1): boom()\n#1 {main}",
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee($batchId)
        ->assertSeeHtml('openBatch');
});

it('renders the batch chip in the details (completed) modal hero', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69MODALCMP';
    $batchId = 'batch-modal-completed';

    R::raw('set', 'qmtest:batch:uuid:' . $uuid, $batchId);

    R::raw('eval', "return redis.call('XADD', KEYS[1], '*', 'class', ARGV[1], 'connection', ARGV[2], 'queue', ARGV[3], 'duration_ms', ARGV[4], 'attempts', ARGV[5], 'processed_at', ARGV[6], 'uuid', ARGV[7])",
        1,
        'qmtest:completed',
        'App\\Jobs\\BatchedJob',
        'redis',
        'work',
        '15',
        '1',
        '2026-04-24T12:00:00+00:00',
        $uuid,
    );

    $component = Livewire::test(QueueInsightsDashboard::class);
    $streamId = $component->html();

    // Pluck the stream id Livewire rendered into the row's wire:click attribute.
    preg_match("/openPayload\\('([^']+)'\\)/", $streamId, $matches);
    $sid = $matches[1] ?? '';
    expect($sid)->not->toBeEmpty();

    $component
        ->call('openPayload', $sid)
        ->assertSeeHtml('aria-labelledby="qi-modal-title"')
        ->assertSee($batchId)
        ->assertSeeHtml('openBatch');
});

it('renders the batch chip in the failed-job modal hero', function (): void {
    $batchId = 'batch-modal-failed';

    $id = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => 'uuid-failed-modal-chip',
        'connection' => 'redis',
        'queue' => 'work',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\BatchedJob',
            'data' => ['commandName' => 'App\\Jobs\\BatchedJob', 'command' => 'O:0:"":0:{}', 'batchId' => $batchId],
        ]),
        'exception' => "RuntimeException: boom\n#0 /app.php(1): boom()\n#1 {main}",
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSeeHtml('aria-labelledby="qi-failed-modal-title"')
        ->assertSee($batchId)
        ->assertSeeHtml('openBatch');
});

it('shows the Back to batch button on item modals when expandedBatchId is set', function (): void {
    $id = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => 'uuid-back-button',
        'connection' => 'redis',
        'queue' => 'work',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => "X: y\n#0 main",
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-back-test'])
        ->call('openFailed', $id)
        ->assertSeeHtml('aria-label="Back to batch"');
});

it('hides the Back to batch button on item modals when no batch is open', function (): void {
    $id = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => 'uuid-no-back',
        'connection' => 'redis',
        'queue' => 'work',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BatchedJob']),
        'exception' => "X: y\n#0 main",
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertDontSeeHtml('aria-label="Back to batch"');
});

it('renders the batch chip on a pending-inspector row from the pending hash field', function (): void {
    config()->set('queue-insights.pending.enabled', true);

    // Seed live counters so the queue row meets the inspector's "tracked > 0"
    // gate; the pending zset+hash drives the actual list.
    $uuid = 'uuid-pending-chip';
    $batchId = 'batch-pending-chip';

    R::raw('hset', 'qmtest:pending:' . $uuid, 'class', 'App\\Jobs\\BatchedJob');
    R::raw('hset', 'qmtest:pending:' . $uuid, 'connection', 'redis');
    R::raw('hset', 'qmtest:pending:' . $uuid, 'queue', 'work');
    R::raw('hset', 'qmtest:pending:' . $uuid, 'queued_at', (string) (Date::now()
        ->getTimestamp() - 5));
    R::raw('hset', 'qmtest:pending:' . $uuid, 'available_at', (string) (Date::now()
        ->getTimestamp() - 5));
    R::raw('hset', 'qmtest:pending:' . $uuid, 'batch_id', $batchId);

    R::raw('zadd', 'qmtest:pending-zset:redis:work', (string) (Date::now()
        ->getTimestamp() - 5), $uuid);

    Livewire::test(QueueInsightsDashboard::class, ['expandedQueueKey' => 'redis:work'])
        ->assertSee($batchId);
});
