<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;
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

    config()->set('queue.connections.myredis', ['driver' => 'redis']);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'myredis', 'queue' => 'work'],
    ]);
});

/**
 * Mirror of the helper used in PendingSectionTest. Kept local so the modal
 * tests can run independently of that suite.
 */
function seedPendingForModal(string $uuid, string $connection, string $queue, string $class, int $availableAt, int $queuedAt): void
{
    foreach ([
        'connection' => $connection,
        'queue' => $queue,
        'class' => $class,
        'queued_at' => (string) $queuedAt,
        'available_at' => (string) $availableAt,
        'batch_id' => '',
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:' . $uuid, $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:' . $connection . ':' . $queue, $availableAt, $uuid]);
}

it('renders the pending row as a clickable button wired to openPending', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('pending-clickable', 'myredis', 'work', 'App\\Jobs\\Clickable', $now - 5, $now - 5);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('wire:click="openPending(\'pending-clickable\')"')
        ->assertSeeHtml('role="button"')
        ->assertSee('Clickable');
});

it('openPending populates selectedPendingUuid and closePending clears it', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('selectedPendingUuid', null)
        ->call('openPending', 'pending-uuid-x')
        ->assertSet('selectedPendingUuid', 'pending-uuid-x')
        ->call('closePending')
        ->assertSet('selectedPendingUuid', null);
});

it('renders the modal with class + connection + queue + UUID for an open pending row', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('pending-modal-1', 'myredis', 'work', 'App\\Jobs\\OpenedPending', $now - 30, $now - 30);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'pending-modal-1')
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-labelledby="qi-pending-modal-title"')
        ->assertSee('Pending job')
        ->assertSee('OpenedPending')
        ->assertSee('myredis')
        ->assertSee('work')
        ->assertSee('pending-modal-1');
});

it('renders Delayed-job header + Runs metric when the row is delayed', function (): void {
    $now = Date::now()->getTimestamp();
    // Available 10 minutes from now → delayed.
    seedPendingForModal('delayed-modal-1', 'myredis', 'work', 'App\\Jobs\\OpenedDelayed', $now + 600, $now);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'delayed-modal-1')
        ->assertSee('Delayed job')
        ->assertSee('OpenedDelayed')
        ->assertSee('Runs');
});

it('shows the "no longer pending" empty state when a worker grabs the row mid-modal', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('vanish-uuid', 'myredis', 'work', 'App\\Jobs\\WillVanish', $now - 5, $now - 5);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'vanish-uuid');

    // Simulate the JobProcessing listener clearing the pending hash + zset.
    R::conn()->command('del', ['qmtest:pending:vanish-uuid']);
    R::conn()->command('zrem', ['qmtest:pending-zset:myredis:work', 'vanish-uuid']);

    // Re-render — the open uuid is still set but the row is gone.
    $component
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', 'vanish-uuid')
        ->assertSee('No longer pending');
});

it('follows a drained pending job to its completed modal when the worker finishes it mid-modal', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('drain-completed', 'myredis', 'work', 'App\\Jobs\\DrainedOk', $now - 5, $now - 5);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'drain-completed')
        ->assertSet('selectedPendingUuid', 'drain-completed');

    // RecordJobProcessed deletes the pending hash + zset and writes the
    // uuid-completed index pointing at the global completed-stream id.
    R::conn()->command('del', [KeyPrefix::make('pending:drain-completed')]);
    R::conn()->command('zrem', [KeyPrefix::make('pending-zset:myredis:work'), 'drain-completed']);
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:drain-completed'), 300, '1700000000-0']);

    // Next poll: the pending modal swaps in place to the completed modal.
    $component
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', null)
        ->assertSet('selectedPayloadId', '1700000000-0');
});

it('follows a drained pending job to its failed modal when the worker fails it mid-modal', function (): void {
    $now = Date::now()->getTimestamp();
    seedPendingForModal('drain-failed', 'myredis', 'work', 'App\\Jobs\\DrainedBad', $now - 5, $now - 5);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'drain-failed')
        ->assertSet('selectedPendingUuid', 'drain-failed');

    // RecordJobFailed deletes the pending hash + zset and writes the
    // uuid-failed index pointing at the failed_jobs row id.
    R::conn()->command('del', [KeyPrefix::make('pending:drain-failed')]);
    R::conn()->command('zrem', [KeyPrefix::make('pending-zset:myredis:work'), 'drain-failed']);
    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:drain-failed'), 300, '77']);

    $component
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', null)
        ->assertSet('selectedFailedId', 77);
});

it('follow-drained-pending bypasses an active class filter when resolving the completed payload', function (): void {
    // Codex review #3: the primary `recentCompleted` read is class-
    // filtered to drive the Completed table, but the auto-follow path
    // sets selectedPayloadId for ANY drained uuid regardless of the
    // operator's class filter. Without the bypass, the entry is
    // filtered out of the primary read and the stale modal misleadingly
    // says "no longer available" — even though the entry is alive.
    $now = Date::now()->getTimestamp();
    seedPendingForModal('drain-class-filtered', 'myredis', 'work', 'App\\Jobs\\Drained', $now - 5, $now - 5);

    // Pin the table to an UNRELATED class so the just-completed entry
    // (`App\\Jobs\\Drained`) is excluded from the primary read.
    $component = Livewire::test(QueueInsightsDashboard::class, ['selectedClass' => 'App\\Jobs\\Unrelated'])
        ->call('openPending', 'drain-class-filtered');

    // Drain the pending hash, seed a matching completed-stream entry,
    // and point the uuid-completed index at its stream id.
    R::conn()->command('del', [KeyPrefix::make('pending:drain-class-filtered')]);
    R::conn()->command('zrem', [KeyPrefix::make('pending-zset:myredis:work'), 'drain-class-filtered']);
    seedStream(R::conn(), KeyPrefix::make('completed'), [
        'class' => 'App\\Jobs\\Drained',
        'uuid' => 'drain-class-filtered',
        'duration_ms' => '50',
        'attempts' => '1',
    ], '1700000123456-0');
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:drain-class-filtered'), 300, '1700000123456-0']);

    $component
        ->call('$refresh')
        // Follow ran → selectedPayloadId set, pending uuid cleared.
        ->assertSet('selectedPendingUuid', null)
        ->assertSet('selectedPayloadId', '1700000123456-0')
        // The details modal mounted (NOT the stale-modal empty state).
        ->assertSeeHtml('aria-labelledby="qi-modal-title"')
        ->assertDontSeeHtml('aria-labelledby="qi-stale-modal-title"');
});

it('keeps the pending modal in place while the job is still in-flight even if a stale uuid-completed index exists', function (): void {
    // Retry quirk: a uuid that previously completed keeps its
    // uuid-completed index (TTL-bound) but is pending again. The pending
    // hash is the source of truth — the modal must NOT yank to completed.
    $now = Date::now()->getTimestamp();
    seedPendingForModal('still-pending', 'myredis', 'work', 'App\\Jobs\\StillPending', $now - 5, $now - 5);
    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:still-pending'), 300, '1700000000-0']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'still-pending')
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', 'still-pending')
        ->assertSet('selectedPayloadId', null);
});

it('falls back to the empty state when a drained job has no uuid index to follow', function (): void {
    // chain-lineage disabled → no uuid-completed / uuid-failed index is
    // written, so a drained job can't be followed. The "no longer
    // pending" empty state is the correct degraded behaviour.
    config()->set('queue-insights.chain_lineage.enabled', false);

    $now = Date::now()->getTimestamp();
    seedPendingForModal('drain-no-index', 'myredis', 'work', 'App\\Jobs\\NoIndex', $now - 5, $now - 5);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'drain-no-index');

    R::conn()->command('del', [KeyPrefix::make('pending:drain-no-index')]);
    R::conn()->command('zrem', [KeyPrefix::make('pending-zset:myredis:work'), 'drain-no-index']);

    $component
        ->call('$refresh')
        ->assertSet('selectedPendingUuid', 'drain-no-index')
        ->assertSet('selectedPayloadId', null)
        ->assertSee('No longer pending');
});

it('hydrates the modal directly from the pending hash when the uuid is outside the top-50 aggregate window', function (): void {
    // Codex regression: clicking a batched-job item from the batch modal
    // sets `selectedPendingUuid`, but if that uuid sits beyond the 50-row
    // global pending/in-flight aggregates, the prior `resolveSelectedPending`
    // returned null and the modal flashed "no longer pending" even though
    // the pending hash still existed. Direct-by-uuid hydration must surface
    // the row regardless of the aggregate window.
    $now = Date::now()->getTimestamp();
    seedPendingForModal('uuid-outside-window', 'myredis', 'work', 'App\\Jobs\\OutsideWindow', $now - 5, $now - 5);

    // Seed > 50 newer pending rows so the target uuid falls outside the
    // global cap allPendingJobs / allInFlightJobs would sample.
    for ($i = 0; $i < 60; ++$i) {
        seedPendingForModal('filler-' . $i, 'myredis', 'work', 'App\\Jobs\\Filler', $now - 1, $now - 1);
    }

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'uuid-outside-window')
        ->assertSee('OutsideWindow')
        ->assertDontSee('No longer pending');
});

it('falls back to the stale modal when pending tracking is disabled', function (): void {
    config()->set('queue-insights.pending.enabled', false);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'whatever-uuid')
        // The full pending modal stays gated off — there's nothing to read
        // when pending tracking never ran...
        ->assertDontSeeHtml('aria-labelledby="qi-pending-modal-title"')
        // ...but the click still lands on the lightweight stale modal so it
        // isn't a dead no-op.
        ->assertSeeHtml('aria-labelledby="qi-stale-modal-title"')
        ->assertSee('Job no longer tracked');
});

it('renders Job config + structured-payload when pending payload capture wrote full fields', function (): void {
    $now = Date::now()->getTimestamp();

    // Seed a hash whose shape matches what RecordJobQueued writes under
    // `pending.capture.payloads = full`: routing metadata + the four
    // payload_* metadata fields + a JSON-encoded payload_body. The modal
    // should pick up both the Job-config tile row AND the structured-
    // payload section in the right column.
    foreach ([
        'connection' => 'myredis',
        'queue' => 'work',
        'class' => 'App\\Jobs\\PayloadVisible',
        'queued_at' => (string) ($now - 5),
        'available_at' => (string) ($now - 5),
        'payload_displayName' => 'App\\Jobs\\PayloadVisible',
        'payload_maxTries' => '3',
        'payload_timeout' => '60',
        'payload_backoff' => '[1,5,10]',
        'payload_body' => (string) json_encode([
            'uuid' => 'pending-cap-full',
            'displayName' => 'App\\Jobs\\PayloadVisible',
            'data' => [
                'commandName' => 'App\\Jobs\\PayloadVisible',
                'command' => 'O:11:"App\\Jobs\\X":0:{}',
            ],
        ]),
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:pending-cap-full', $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:myredis:work', $now - 5, 'pending-cap-full']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'pending-cap-full')
        ->assertSeeHtml('aria-labelledby="qi-pending-modal-title"')
        // Metadata tile row + values.
        ->assertSee('maxTries')
        ->assertSee('timeout')
        ->assertSee('backoff')
        ->assertSee('1, 5, 10s')
        // Structured-payload section landed too.
        ->assertSee('PayloadVisible');
});

it('renders Job config tiles when pending capture is metadata-only (no body)', function (): void {
    $now = Date::now()->getTimestamp();

    // Same seed minus `payload_body` — the metadata-mode path. The modal
    // should still surface maxTries / timeout / backoff so a metadata-
    // only capture isn't an empty right column.
    foreach ([
        'connection' => 'myredis',
        'queue' => 'work',
        'class' => 'App\\Jobs\\MetadataOnly',
        'queued_at' => (string) ($now - 5),
        'available_at' => (string) ($now - 5),
        'payload_displayName' => 'App\\Jobs\\MetadataOnly',
        'payload_maxTries' => '5',
        'payload_timeout' => '90',
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:pending-cap-meta', $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:myredis:work', $now - 5, 'pending-cap-meta']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'pending-cap-meta')
        ->assertSeeHtml('aria-labelledby="qi-pending-modal-title"')
        ->assertSee('Job config')
        ->assertSee('maxTries')
        ->assertSee('5')
        ->assertSee('timeout')
        ->assertSee('90');
});

it('renders the payload-not-persisted note for a closure pending job', function (): void {
    $now = Date::now()->getTimestamp();

    foreach ([
        'connection' => 'myredis',
        'queue' => 'work',
        'class' => 'Closure',
        'queued_at' => (string) ($now - 5),
        'available_at' => (string) ($now - 5),
        'payload_note' => 'payload_not_persisted',
        'payload_reason' => 'closure_or_encrypted',
    ] as $field => $value) {
        R::conn()->command('hset', ['qmtest:pending:pending-closure', $field, $value]);
    }

    R::conn()->command('zadd', ['qmtest:pending-zset:myredis:work', $now - 5, 'pending-closure']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPending', 'pending-closure')
        ->assertSee('Payload not persisted')
        ->assertSee('closure or encrypted');
});
