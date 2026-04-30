<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\RowEnricher;
use SanderMuller\QueueInsights\Support\UuidResolver;
use SanderMuller\QueueInsights\Tests\Support\ChainChildJob;
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

function workSurface(int $jobs): void
{
    $worker = App::make('queue.worker');
    if (! $worker instanceof Worker) {
        throw new RuntimeException('queue.worker is not bound to a Worker instance');
    }

    for ($i = 0; $i < $jobs; ++$i) {
        $worker->runNextJob('database', 'default', new WorkerOptions());
    }
}

it('RowEnricher::completed exposes parent_uuid and resolves parent_class', function (): void {
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    workSurface(2);

    $entries = StreamEntries::fromXrange(R::raw('xrange', 'qmtest:completed', '-', '+'));
    expect($entries)->toHaveCount(2);

    // Reshape to RowEnricher::completed input — list of associative arrays
    // with `_id` keys (the existing reader path adds _id).
    $rawRows = [];
    foreach ($entries as $id => $fields) {
        $row = $fields;
        $row['_id'] = $id;
        $rawRows[] = $row;
    }

    $enriched = RowEnricher::completed($rawRows);

    $byClass = [];
    foreach ($enriched as $row) {
        $class = $row['class'] ?? null;
        if (is_string($class)) {
            $byClass[$class] = $row;
        }
    }

    expect($byClass[ChainParentJob::class]['parent_uuid'])->toBeNull()
        ->and($byClass[ChainChildJob::class]['parent_uuid'])->toBe($byClass[ChainParentJob::class]['uuid'])
        ->and($byClass[ChainChildJob::class]['parent_class'])->toBe(ChainParentJob::class);
});

it('RowEnricher::failed reads qi:lineage and qi:class side-channels', function (): void {
    // Seed a fake parent class + child lineage hash, then drive the enricher
    // with a synthetic failed row (no real failed_jobs table needed for this
    // unit-level assertion).
    $parentUuid = 'parent-uuid-xyz';
    $childUuid = 'child-uuid-abc';

    R::conn()->command('setex', [ParentClassResolver::classKey($parentUuid), 604800, 'App\\Jobs\\Parent']);

    (new ChainLineageStore())->writeLineage($childUuid, $parentUuid, 604800);

    $enriched = RowEnricher::failed([
        [
            'id' => 99,
            'uuid' => $childUuid,
            'connection' => 'database',
            'queue' => 'default',
            'failed_at' => '2026-04-29T00:00:00+00:00',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Child', 'attempts' => 1]),
            'exception' => 'RuntimeException: boom',
        ],
    ]);

    expect($enriched[0]['parent_uuid'])->toBe($parentUuid)
        ->and($enriched[0]['parent_class'])->toBe('App\\Jobs\\Parent');
});

it('parent_class is null when the parent has aged out of retention', function (): void {
    $parentUuid = 'aged-out-parent';
    $childUuid = 'still-here-child';

    // Lineage hash present, class side-key absent → null class label.
    (new ChainLineageStore())->writeLineage($childUuid, $parentUuid, 604800);

    $enriched = RowEnricher::failed([
        [
            'id' => 1,
            'uuid' => $childUuid,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Child', 'attempts' => 1]),
            'exception' => 'RuntimeException: boom',
        ],
    ]);

    expect($enriched[0]['parent_uuid'])->toBe($parentUuid)
        ->and($enriched[0]['parent_class'])->toBeNull();
});

it('parent-lineage-row partial renders when parent_uuid is set', function (): void {
    $html = view('queue-insights::partials.parent-lineage-row', [
        'parentUuid' => 'abc-uuid-1234',
        'parentClass' => 'App\\Jobs\\Parent',
        'copyId' => 'qi-test-parent-uuid',
    ])->render();

    expect($html)
        ->toContain('abc-uuid-1234')
        ->toContain('App\\Jobs\\Parent')
        ->toContain('qi-test-parent-uuid');
});

it('parent-lineage-row partial omits class label gracefully when null', function (): void {
    $html = view('queue-insights::partials.parent-lineage-row', [
        'parentUuid' => 'abc-uuid-5678',
        'parentClass' => null,
        'copyId' => 'qi-test-parent-uuid',
    ])->render();

    expect($html)->toContain('abc-uuid-5678')
        ->toContain('—');
});

it('parent-lineage-row partial renders nothing when parent_uuid is null', function (): void {
    $html = view('queue-insights::partials.parent-lineage-row', [
        'parentUuid' => null,
        'parentClass' => null,
        'copyId' => 'qi-test-parent-uuid',
    ])->render();

    expect(trim($html))
        ->toBeEmpty();
});

it('failed-modal markdown export includes Parent line with class when both are set', function (): void {
    $html = renderFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'parent_uuid' => 'parent-of-failed',
        'parent_class' => 'App\\Jobs\\Y',
    ]);

    expect($html)->toContain('**Parent:** `parent-of-failed`')
        ->toContain('(App\\Jobs\\Y)');
});

it('failed-modal markdown export omits parent class label when only uuid is known', function (): void {
    $html = renderFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'parent_uuid' => 'parent-of-failed',
        'parent_class' => null,
    ]);

    expect($html)->toContain('**Parent:** `parent-of-failed`')
        ->not->toContain('(App\\Jobs\\Y)');
});

it('UuidResolver routes a uuid to whichever surface it lives on', function (): void {
    // Three side-keys, three resolutions — completed (stream id),
    // failed (db id), pending (uuid). Aged-out fourth uuid resolves to
    // null so the click-through fallback can show its "no longer
    // available" flash banner.
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:resolved-completed'), 300, '1700000000-0']);
    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:resolved-failed'), 300, '42']);
    R::conn()->command('hset', [KeyPrefix::make('pending:resolved-pending'), 'class', 'App\\Jobs\\X']);

    expect(UuidResolver::resolve('resolved-completed'))
        ->toBe(['type' => 'completed', 'id' => '1700000000-0'])
        ->and(UuidResolver::resolve('resolved-failed'))
        ->toBe(['type' => 'failed', 'id' => 42])
        ->and(UuidResolver::resolve('resolved-pending'))
        ->toBe(['type' => 'pending', 'id' => 'resolved-pending'])
        ->and(UuidResolver::resolve('aged-out'))
        ->toBeNull();
});

it('openByUuid dispatches to openPayload for a completed parent', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:click-through-completed'), 300, '1700000000-0']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'click-through-completed')
        ->assertSet('selectedPayloadId', '1700000000-0')
        ->assertSet('selectedFailedId', null)
        ->assertSet('selectedPendingUuid', null);
});

it('openByUuid dispatches to openPending for an in-flight / pending parent', function (): void {
    R::conn()->command('hset', [KeyPrefix::make('pending:click-through-pending'), 'class', 'App\\Jobs\\X']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'click-through-pending')
        ->assertSet('selectedPendingUuid', 'click-through-pending')
        ->assertSet('selectedPayloadId', null)
        ->assertSet('selectedFailedId', null);
});

it('openByUuid dispatches to openFailed for a failed parent', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:click-through-failed'), 300, '99']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'click-through-failed')
        ->assertSet('selectedFailedId', 99)
        ->assertSet('selectedPayloadId', null)
        ->assertSet('selectedPendingUuid', null);
});

it('chainBack returns the user to the modal they came from', function (): void {
    // Two modals seeded — child (failed) + parent (completed). User
    // opens the child, clicks `↰ From` to navigate to parent, then
    // chainBack to return to the child's failed modal.
    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:back-stack-child'), 300, '7']);
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:back-stack-parent'), 300, '1700000000-0']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'back-stack-child')
        ->assertSet('selectedFailedId', 7)
        ->call('openByUuid', 'back-stack-parent', 'App\\Jobs\\Child')
        ->assertSet('selectedPayloadId', '1700000000-0')
        ->assertSet('selectedFailedId', null)
        ->call('chainBack')
        ->assertSet('selectedFailedId', 7)
        ->assertSet('selectedPayloadId', null)
        ->assertSet('chainBackStack', []);
});

it('closing a modal clears the chain back stack', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:close-clears-stack-parent'), 300, '1700000000-0']);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('selectedFailedId', 99)
        ->call('openByUuid', 'close-clears-stack-parent', 'App\\Jobs\\Child');

    // Stack has the prior failed modal frame.
    expect($component->get('chainBackStack'))->toHaveCount(1);

    // Close the parent modal (X / Esc) — stack drops.
    $component->call('closePayload')
        ->assertSet('chainBackStack', []);
});

it('openByUuid opens nothing when the parent has aged out', function (): void {
    // No `uuid-completed`/`uuid-failed`/`pending:` keys for this uuid →
    // UuidResolver returns null → openByUuid early-returns without
    // touching any selection. (A `qi.retry.error` flash is set for the
    // dashboard to render but session driver isn't wired in unit tests.)
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'never-existed')
        ->assertSet('selectedPayloadId', null)
        ->assertSet('selectedFailedId', null)
        ->assertSet('selectedPendingUuid', null);
});

it('completed details modal hydrates parent_uuid and parent_class for the selected stream entry', function (): void {
    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    workSurface(2);

    // Find the child's stream id via the same reader the dashboard uses,
    // so the test mirrors the production code path end-to-end.
    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $childId = null;
    foreach ($completed as $row) {
        if (($row['class'] ?? null) === ChainChildJob::class) {
            $childId = $row['_id'] ?? null;
            break;
        }
    }

    expect($childId)->toBeString();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $childId)
        // Both the parent uuid AND the class label make it through the
        // DashboardData hydration pass into the modal payload, so the
        // `↰ From` partial renders with `(ChainParentJob)`.
        ->assertSee(ChainParentJob::class);
});

it('pending / in-flight modal hydrates parent_uuid and parent_class for chained children', function (): void {
    // Seed a pending hash with the parent_uuid stamp that
    // RecordJobProcessing::copyLineageToPending writes when a chained
    // child enters processing, plus the qi:class:{uuid} side-key. Mount
    // the dashboard, open the pending modal, and assert both the uuid
    // and the class label make it through DashboardData hydration.
    $childUuid = 'pending-child-uuid';
    $parentUuid = 'pending-parent-uuid';
    $parentClass = 'App\\Jobs\\PendingParent';

    R::conn()->command('setex', [ParentClassResolver::classKey($parentUuid), 604800, $parentClass]);

    foreach ([
        'connection' => 'redis',
        'queue' => 'default',
        'class' => 'App\\Jobs\\PendingChild',
        'queued_at' => '1700000000',
        'available_at' => '1700000000',
        'batch_id' => '',
        'parent_uuid' => $parentUuid,
    ] as $field => $value) {
        R::conn()->command('hset', [KeyPrefix::make("pending:{$childUuid}"), $field, $value]);
    }

    R::conn()->command('zadd', [
        KeyPrefix::make('pending-zset:redis:default'),
        1700000000,
        $childUuid,
    ]);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', $childUuid)
        ->assertSee($parentClass)
        ->assertSee($parentUuid);
});

it('failed details modal hydrates parent_uuid and parent_class via the lineage hash', function (): void {
    // Seed a synthetic failed_jobs row + the side-channel keys, then drive
    // the Livewire dashboard's openFailed action. Bypasses the real failure
    // path (which would need an actual chain to throw mid-process) — the
    // hydration logic is the same regardless of how the row got there.
    $childUuid = 'failed-child-uuid';
    $parentUuid = 'failed-parent-uuid';
    $parentClass = 'App\\Jobs\\FailedParent';

    R::conn()->command('setex', [ParentClassResolver::classKey($parentUuid), 604800, $parentClass]);
    (new ChainLineageStore())->writeLineage($childUuid, $parentUuid, 604800);

    Schema::create('failed_jobs', function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('uuid')->unique();
        $t->text('connection');
        $t->text('queue');
        $t->longText('payload');
        $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });

    $failedId = DB::table('failed_jobs')->insertGetId([
        'uuid' => $childUuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Child', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'failed_at' => '2026-04-29 00:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $failedId)
        ->assertSee($parentClass)
        ->assertSee($parentUuid);
});

it('failed-modal markdown export omits Parent line entirely when parent_uuid is null', function (): void {
    $html = renderFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'parent_uuid' => null,
        'parent_class' => null,
    ]);

    expect($html)->not->toContain('**Parent:**');
});

/**
 * Render the `<x-queue-insights::failed-modal>` component to a string.
 * Component-based blade views are not addressable via `view()` directly —
 * Blade::render compiles the component invocation in-process.
 *
 * @param  array<string, mixed>  $failed
 */
function renderFailedModal(array $failed): string
{
    return Blade::render(
        '<x-queue-insights::failed-modal :failed="$failed" :canRetry="false" expandedBatchId="" />',
        ['failed' => $failed],
    );
}
