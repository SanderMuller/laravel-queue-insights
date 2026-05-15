<?php declare(strict_types=1);

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
    Schema::dropIfExists('job_batches');
    Schema::dropIfExists('failed_jobs');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function seedBatchRow(string $id, array $overrides = []): void
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

it('renders the Batches section header when batches exist', function (): void {
    seedBatchRow('batch-aaa', ['name' => 'ImportCustomers']);
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-aaa');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Batches')
        ->assertSee('ImportCustomers')
        ->assertSeeHtml('id="qi-batches-section"');
});

it('shows the empty-state message when no batches are tracked', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('No active batches.');
});

it('hides the Batches section entirely when batches.enabled is false', function (): void {
    config()->set('queue-insights.batches.enabled', false);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSeeHtml('id="qi-batches-section"');
});

it('renders the progress percentage from the joined Bus::findBatch counts', function (): void {
    // 3 total, 1 pending => 67% progress (rounded). The percentage drives the
    // bar width, so the calculation has to be wired all the way to the view.
    seedBatchRow('batch-bbb', ['total_jobs' => 3, 'pending_jobs' => 1, 'failed_jobs' => 0]);
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-bbb');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('67%');
});

it('toggles the expanded batch via the URL-shareable expandedBatchId prop', function (): void {
    seedBatchRow('batch-ccc');
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-ccc');
    R::raw('rpush', 'qmtest:batch:batch-ccc:uuids', 'uuid-1', 'uuid-2');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('expandedBatchId', '')
        ->call('toggleBatchInspector', 'batch-ccc')
        ->assertSet('expandedBatchId', 'batch-ccc')
        ->call('toggleBatchInspector', 'batch-ccc')
        ->assertSet('expandedBatchId', '');
});

it('renders the per-uuid item list in enqueue order when expanded', function (): void {
    seedBatchRow('batch-ddd');
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp(), 'batch-ddd');
    R::raw('rpush', 'qmtest:batch:batch-ddd:uuids', 'uuid-alpha', 'uuid-beta', 'uuid-gamma');

    // uuid-alpha = completed (stream id present)
    R::raw('set', 'qmtest:uuid-completed:uuid-alpha', '1700000000-0');

    // uuid-beta = failed (failed_jobs row + uuid-failed index)
    $failedId = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => 'uuid-beta',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BetaJob']),
        'exception' => 'boom',
        'failed_at' => '2026-04-24 12:00:00',
    ]);
    R::raw('set', 'qmtest:uuid-failed:uuid-beta', (string) $failedId);

    // uuid-gamma = pending (pending hash present, no stream/failed index)
    R::raw('hset', 'qmtest:pending:uuid-gamma', 'class', 'App\\Jobs\\GammaJob');
    R::raw('hset', 'qmtest:pending:uuid-gamma', 'queued_at', (string) (Date::now()
        ->getTimestamp() - 10));

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-ddd'])
        // Completed item — class FQCN comes from the modal flow, the row
        // shows the short uuid suffix and a click target wired to openPayload.
        ->assertSeeHtml('wire:click="openPayload(\'1700000000-0\')"')
        ->assertSeeHtml("wire:click=\"openFailed({$failedId})\"")
        // Class FQCNs render as namespace-faded + leaf-bold spans
        // (matches the completed-list row rhythm), so the FQCN is not a
        // contiguous substring in the HTML. Assert on the leaf and namespace
        // separately, then pin the leaf to the bold wrapper so a stray
        // payload text occurrence can't false-positive.
        ->assertSee('App\\Jobs\\')
        ->assertSeeHtml('>BetaJob</span>')
        ->assertSeeHtml('>GammaJob</span>');
});

it('renders an in-flight batch item with running chip when the pending hash carries state=in_flight', function (): void {
    // Codex regression: a batched job that's actively running should render
    // as in_flight (▶ + running chip), not pending (⌛). The pending hash
    // gains `state=in_flight` after RecordJobProcessing runs, so we
    // hand-seed the same shape here.
    seedBatchRow('batch-running');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-running');
    R::raw('rpush', 'qmtest:batch:batch-running:uuids', 'running-uuid');

    R::raw('hset', 'qmtest:pending:running-uuid', 'class', 'App\\Jobs\\RunningBatchedJob');
    R::raw('hset', 'qmtest:pending:running-uuid', 'queued_at', (string) (Date::now()->getTimestamp() - 60));
    R::raw('hset', 'qmtest:pending:running-uuid', 'available_at', (string) (Date::now()->getTimestamp() - 60));
    R::raw('hset', 'qmtest:pending:running-uuid', 'state', 'in_flight');
    R::raw('hset', 'qmtest:pending:running-uuid', 'started_at', (string) (Date::now()->getTimestamp() - 5));

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-running'])
        ->assertSee('RunningBatchedJob')
        // The running chip uses `bg-amber-50` — pin to that + the visible
        // word so a chrome rename surfaces in tests.
        ->assertSeeHtml('bg-amber-50')
        ->assertSee('running')
        // In-flight items click into the pending modal, NOT openPayload.
        ->assertSeeHtml("openPending('running-uuid')");
});

it('clicking a batch row mounts the batch modal with identity + items list', function (): void {
    seedBatchRow('batch-modal-open');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-modal-open');
    R::raw('rpush', 'qmtest:batch:batch-modal-open:uuids', 'uuid-aaa', 'uuid-bbb');

    Livewire::test(QueueInsightsDashboard::class)
        ->call('toggleBatchInspector', 'batch-modal-open')
        ->assertSet('expandedBatchId', 'batch-modal-open')
        ->assertSeeHtml('aria-labelledby="qi-batch-modal-title"')
        // Identity hero shows the seeded batch name.
        ->assertSee('ImportCustomers')
        // Items list shows each seeded uuid in full (no truncation — the
        // row has the width for it).
        ->assertSee('uuid-aaa')
        ->assertSee('uuid-bbb');
});

it('falls back to the stale modal when openPayload targets a trimmed completed entry', function (): void {
    // A batch-item click routes to openPayload(streamId). When the global
    // completed stream has trimmed that entry past the read window the
    // resolved payload is null — without the fallback the click reads as a
    // dead no-op. The dashboard mounts <stale-modal> instead.
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', '1700000000-0')
        ->assertSeeHtml('aria-labelledby="qi-stale-modal-title"')
        ->assertSee('Completed job no longer available')
        // A mounted stale modal still inerts the dashboard behind it.
        ->assertSeeHtml('x-bind:inert="true"')
        ->call('closePayload')
        ->assertDontSeeHtml('aria-labelledby="qi-stale-modal-title"')
        ->assertSeeHtml('x-bind:inert="false"');
});

it('falls back to the stale modal when openFailed targets a pruned failed_jobs row', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', 999999)
        ->assertSeeHtml('aria-labelledby="qi-stale-modal-title"')
        ->assertSee('Failed job no longer available')
        ->call('closeFailed')
        ->assertDontSeeHtml('aria-labelledby="qi-stale-modal-title"');
});

it('stale modal binds escape on the dialog with stopPropagation so it does not also close the batch modal underneath', function (): void {
    // Codex review #2: the stale modal previously used
    // `x-on:keydown.escape.window`. The batch modal binds its own
    // window-level escape handler, so a single Escape keypress would
    // fire both listeners and tear down BOTH modals in lockstep —
    // defeating the "close item modal, return to batch view" stacking
    // pattern. The fixed binding is on the dialog element (NOT window)
    // and stops propagation so the bubbled keydown never reaches the
    // batch modal's window handler.
    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', 'stale-stream-id')
        ->html();

    expect($html)
        ->toContain('$event.stopPropagation()')
        // The naive window-level binding must NOT be regenerated. Pin
        // both the `.window` modifier AND the bare close-action call
        // so a future refactor can't silently regress to either shape.
        ->not->toContain('x-on:keydown.escape.window="$wire.closePayload')
        ->not->toContain('x-on:keydown.escape="$wire.closePayload');
});

it('a batch item whose completed entry was trimmed opens the stale modal over the batch modal', function (): void {
    seedBatchRow('batch-stale-item');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-stale-item');
    R::raw('rpush', 'qmtest:batch:batch-stale-item:uuids', 'trimmed-uuid');
    // The uuid-completed index survives (7-day TTL) but the completed-stream
    // entry it points at has already been trimmed past the read window.
    R::raw('set', 'qmtest:uuid-completed:trimmed-uuid', '1700000000-5');

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-stale-item'])
        // The item renders as completed → routes to openPayload(streamId).
        ->assertSeeHtml("openPayload('1700000000-5')")
        ->call('openPayload', '1700000000-5')
        ->assertSeeHtml('aria-labelledby="qi-stale-modal-title"')
        ->assertSee('Completed job no longer available')
        // The batch modal stays mounted underneath — closing the stale modal
        // returns the operator to the batch view they came from.
        ->assertSeeHtml('aria-labelledby="qi-batch-modal-title"');
});

it('does not freeze the dashboard inert when expandedBatchId is set but batches.enabled is false', function (): void {
    // Codex regression: a shared `?batch=...` URL with batches disabled
    // would set inert without mounting the batch modal — dashboard frozen
    // with no close affordance. The render-time `hasOpenModal` flag must
    // mirror the actual modal mount conditions.
    config()->set('queue-insights.batches.enabled', false);

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-X'])
        // The modal must NOT mount (batches disabled gates the @if).
        ->assertDontSeeHtml('aria-labelledby="qi-batch-modal-title"')
        // The inert binding emits a literal `false` when no modal is open.
        ->assertSeeHtml('x-bind:inert="false"');
});

it('closeBatch unconditionally clears expandedBatchId even when the row was raced away', function (): void {
    // Open the modal with a batch id that has no matching BatchRepository
    // row (the resolver returns null and the modal renders its empty state).
    // closeBatch must close the modal — the prior close path routed through
    // toggleBatchInspector with a fallback id, which would have set
    // expandedBatchId to the fallback value instead of clearing it.
    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-aged-out'])
        ->call('closeBatch')
        ->assertSet('expandedBatchId', '');
});

it('mounts the batch modal via direct lookup when the open batch is outside the recent-batches window', function (): void {
    // Codex regression: openBatch() is reachable from completed/failed/pending
    // batch chips whose reverse-uuid lookup has no recency filter, but the
    // prior resolveSelectedBatch only scanned BatchReader::sectionRows() which
    // is capped at batches.max_per_query (default 100). A retained batch
    // older than that cap landed on the misleading "Batch no longer tracked"
    // empty state even though Bus::findBatch() still resolves it. The fallback
    // path must hit the batch directly.
    config()->set('queue-insights.batches.max_per_query', 1);

    // Seed two batches; only the newest stays inside the section cap of 1.
    seedBatchRow('batch-newest');
    seedBatchRow('batch-older', ['name' => 'OlderRetainedBatch']);
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-newest');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp() - 3600, 'batch-older');
    R::raw('rpush', 'qmtest:batch:batch-older:uuids', 'uuid-old-1');

    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-older'])
        ->assertSee('OlderRetainedBatch')
        ->assertDontSee('Batch no longer tracked');
});

it('opening a per-item modal preserves expandedBatchId so the user can return to the batch view', function (): void {
    seedBatchRow('batch-drill');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-drill');
    R::raw('rpush', 'qmtest:batch:batch-drill:uuids', 'uuid-completed-drill');
    R::raw('set', 'qmtest:uuid-completed:uuid-completed-drill', '1700000000-0');

    // Item modals stack on top of the batch modal (dashboard.blade.php
    // renders batch first, items after). expandedBatchId must persist so
    // close{Payload,Failed,Pending} restores the batch view underneath —
    // same back-and-forth pattern the chain detail uses inside one modal,
    // generalised across modals.
    Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-drill'])
        ->call('openPayload', '1700000000-0')
        ->assertSet('expandedBatchId', 'batch-drill')
        ->assertSet('selectedPayloadId', '1700000000-0')
        ->call('closePayload')
        ->assertSet('selectedPayloadId', null)
        ->assertSet('expandedBatchId', 'batch-drill');
});

it('openBatch closes any open item modal and opens the batch', function (): void {
    seedBatchRow('batch-jump');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-jump');

    // User opened a completed item from outside the batch context, then
    // clicked the batch chip in the modal — openBatch swaps the item modal
    // for the batch modal in one round-trip.
    Livewire::test(QueueInsightsDashboard::class)
        ->set('selectedPayloadId', '1700000000-0')
        ->call('openBatch', 'batch-jump')
        ->assertSet('selectedPayloadId', null)
        ->assertSet('selectedFailedId', null)
        ->assertSet('selectedPendingUuid', null)
        ->assertSet('expandedBatchId', 'batch-jump');
});

it('openBatch is a no-op for an empty id', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('selectedPayloadId', '1700000000-0')
        ->call('openBatch', '')
        ->assertSet('selectedPayloadId', '1700000000-0')
        ->assertSet('expandedBatchId', '');
});

it('safely escapes single-quoted uuids in batch item wire:click bindings', function (): void {
    // Codex regression: the prior batch-modal item code assembled wire:click
    // attribute strings with raw `e()` HTML escaping, which decoded back to
    // a literal `'` inside the Livewire expression and broke the binding.
    // Replaced with `@js()` Blade directive that emits a JS-string literal.
    // Today framework-generated uuids never contain quotes — this test pins
    // the escape behavior so a future change that loosens the input
    // invariant doesn't silently turn the modal into an injection sink.
    $hostileUuid = "uuid-with-'-quote";
    seedBatchRow('batch-quote-uuid');
    R::raw('zadd', 'qmtest:batches:index', Date::now()->getTimestamp(), 'batch-quote-uuid');
    R::raw('rpush', 'qmtest:batch:batch-quote-uuid:uuids', $hostileUuid);

    R::raw('hset', 'qmtest:pending:' . $hostileUuid, 'class', 'App\\Jobs\\HostileUuid');
    R::raw('hset', 'qmtest:pending:' . $hostileUuid, 'queued_at', (string) (Date::now()->getTimestamp() - 5));
    R::raw('hset', 'qmtest:pending:' . $hostileUuid, 'available_at', (string) (Date::now()->getTimestamp() - 5));

    $html = (string) Livewire::test(QueueInsightsDashboard::class, ['expandedBatchId' => 'batch-quote-uuid'])
        ->html();

    // The `'` in the uuid must NOT appear unescaped inside an openPending
    // attribute — that would close the wire:click expression early and
    // smuggle the rest of the uuid into attribute space. `@js()` emits the
    // sequence `'` (or `\'`) so the literal apostrophe never leaks.
    expect($html)->not->toContain("openPending('uuid-with-'-quote')");
    // And the opening attribute prefix should still be present — i.e. the
    // binding rendered, just safely.
    expect($html)->toContain('openPending(');
});

it('renders the cancelled chip on a cancelled batch', function (): void {
    seedBatchRow('batch-cancelled', [
        'cancelled_at' => Date::now()
            ->getTimestamp() - 30,
        'finished_at' => Date::now()
            ->getTimestamp() - 20,
    ]);
    R::raw('zadd', 'qmtest:batches:index', Date::now()
        ->getTimestamp() - 60, 'batch-cancelled');

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('cancelled');
});
