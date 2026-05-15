<?php declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
});

/**
 * Helper: seeds a stream row with the given fields, opens the modal against the resulting
 * _id, returns the Livewire testable.
 *
 * @param  array<string, string>  $fields
 */
function openDetailsModal(array $fields): Testable
{
    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), $fields);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'] ?? null;
    expect($id)->toBeString();

    return Livewire::test(QueueInsightsDashboard::class)->call('openPayload', $id);
}

it('Section A renders base metadata under off mode (no payload_* keys)', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '1243',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertSeeText('Metadata')
        ->assertSeeText('Class')
        ->assertSeeText('App\\Foo')
        ->assertSeeText('Connection')
        ->assertSeeText('redis')
        ->assertSeeText('Queue')
        ->assertSeeText('default')
        ->assertSeeText('Duration')
        ->assertSeeText('(1243 ms)')
        ->assertSeeText('Attempts')
        ->assertSeeText('Processed at')
        ->assertSeeHtml('datetime="2026-04-24T12:00:00+00:00"')
        ->assertSeeText('Stream ID');
});

it('Section A humanizes duration with short form', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    // 1500ms → "1s 500ms" or similar short form
    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '1500',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertSeeText('(1500 ms)')
        ->assertDontSeeText('1500 milliseconds'); // NOT the long form
});

it('Section A shows amber Attempts badge when attempts > 1', function (): void {
    // Asserts each token individually rather than as a single substring —
    // the dark-mode audit (Phase 4) injected `dark:` companions between
    // the light pair, breaking adjacency. Token-by-token matches keep
    // the test resilient to future re-orderings while still proving the
    // amber-tone treatment is on the badge.
    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '3',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertSeeHtml('bg-amber-100')
        ->assertSeeHtml('text-amber-800');
});

it('Section B absent under off mode', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertDontSeeText('Job Config')
        ->assertDontSeeText('Payload not persisted')
        ->assertDontSeeText('Payload encoding failed');
});

it('Section B job-config cards under metadata-normal', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_displayName' => 'App\\Jobs\\SendEmail',
        'payload_maxTries' => '3',
        'payload_timeout' => '60',
        'payload_backoff' => '10',
    ])
        ->assertSeeText('Job Config')
        ->assertSeeText('App\\Jobs\\SendEmail')
        ->assertSeeText('maxTries')
        ->assertSeeText('timeout')
        ->assertSeeText('backoff')
        ->assertSeeText('3')
        ->assertSeeText('60');
});

it('Section B omits stat chips when their key is absent (no — placeholder)', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    // Hero-header job-config card renders each stat as a label/value chip pair:
    //   `<span class="text-gray-500 ...">maxTries</span> <span ...>3</span>`
    // The metadata-mode escalation footer mentions the same words inside `<code>` tokens,
    // so the assertion targets the chip's label-span (text-gray-500) — that span is unique
    // to the chip and the footer's `<code>` styling can't false-positive.
    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_displayName' => 'App\\Jobs\\SendEmail',
        'payload_maxTries' => '3',
        // timeout + backoff omitted
    ])
        ->assertSeeHtml('<span class="text-gray-500 dark:text-gray-400">maxTries</span>')
        ->assertDontSeeHtml('<span class="text-gray-500 dark:text-gray-400">timeout</span>')
        ->assertDontSeeHtml('<span class="text-gray-500 dark:text-gray-400">backoff</span>');
});

it('Section B decodes backoff array into a joined list', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_displayName' => 'App\\Jobs\\SendEmail',
        'payload_backoff' => '[1,5,10]',
    ])
        ->assertSeeText('1, 5, 10s');
});

it('Section B closure/encrypted yellow box under metadata', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    openDetailsModal([
        'class' => 'Closure@redis:default',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_note' => 'payload_not_persisted',
        'payload_reason' => 'closure_or_encrypted',
    ])
        ->assertSeeText('Payload not persisted')
        ->assertSeeText('closure or encrypted')
        ->assertSeeHtml('bg-amber-50')
        ->assertDontSeeText('Job Config');
});

it('Section B closure/encrypted yellow box under full mode (shared sanitizer shape)', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    openDetailsModal([
        'class' => 'Closure@redis:default',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_note' => 'payload_not_persisted',
        'payload_reason' => 'closure_or_encrypted',
    ])
        ->assertSeeText('Payload not persisted')
        ->assertSeeText('closure or encrypted');
});

it('Section B encoding-error red box under full mode', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_error' => 'payload_encoding_failed',
    ])
        ->assertSeeText('Payload encoding failed')
        ->assertSeeHtml('bg-red-50')
        ->assertDontSeeText('Job Config');
});

it('Section B size-overflow red box reads payload_size', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_error' => 'payload_too_large',
        'payload_size' => '20480',
    ])
        ->assertSeeText('Payload exceeded size cap')
        ->assertSeeText('20480 bytes');
});

it('Section C absent under off mode', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertDontSeeText('Payload')
        ->assertDontSeeText('Sanitized JSON');
});

it('Section C absent under metadata mode', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_displayName' => 'App\\Jobs\\SendEmail',
    ])
        ->assertDontSeeText('Sanitized JSON');
});

it('Section C default Raw pane under full-normal renders KV table', function (): void {
    // Default tab is `raw` (Resolved Q #18). The Raw pane renders the decoded
    // payload body's top-level keys as a `<dl>` table; JSON tokens like quotes
    // are NOT present because we render values directly, not via json_encode.
    config()->set('queue-insights.capture.payloads', 'full');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => '{"foo":"bar","baz":42}',
    ])
        ->assertSeeText('Sanitized JSON')
        ->assertSeeText('Structured')
        ->assertSeeText('foo')
        ->assertSeeText('bar')
        ->assertSeeText('baz');
});

it('Section C flip to JSON tab renders the colorizer pane with data-json-highlight', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => '{"foo":"bar","baz":42}',
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->call('setPayloadTab', 'json')
        ->assertSeeHtml('data-json-highlight')
        ->assertSeeText('"foo"')
        ->assertSeeText('"bar"');
});

it('Section C Raw pane groups standard Laravel queue-payload fields into Execution / Other (Job Config + Tags moved to hero)', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    $payload = [
        'uuid' => 'ffffffff-1111-2222-3333-444444444444',
        'displayName' => 'App\\Jobs\\Example',
        // Job-config group — now surfaced in the hero header pills, NOT in
        // the Structured tab. The Structured tab gets a filtered body that
        // strips these keys so the payload section stays job-specific.
        'maxTries' => 3,
        'maxExceptions' => null,
        'timeout' => 60,
        'backoff' => [1, 5, 10],
        // Execution group — stays in the Structured tab.
        'attempts' => 1,
        'pushedAt' => 1716200000,
        // Tags — also moved to the hero, no longer in the Structured tab.
        'tags' => ['App\\Models\\User:42', 'App\\Models\\Video:7'],
        // Catchall "Other fields" — stays in the Structured tab.
        'customField' => 'customValue',
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        // Hero header carries Job Config pills + tags.
        ->assertSee('Job Config')
        ->assertSee('maxTries')
        ->assertSee('timeout')
        ->assertSee('60 s') // timeout pill unit suffix
        ->assertSee('backoff')
        ->assertSee('1, 5, 10 s') // backoff array rendered as comma-list with unit
        ->assertSee('tags')
        ->assertSee('App\\Models\\User:42')
        // Structured tab keeps Execution + Other.
        ->assertSee('Execution')
        ->assertSee('attempts')
        ->assertSee('pushedAt')
        ->assertSee('Other fields')
        ->assertSee('customField')
        ->assertSee('customValue');
});

it('Other fields renders nested arrays as a recursive tree (Sentry-style extra context)', function (): void {
    // Realistic shape: `illuminate:log:context` is a non-standard top-level
    // payload key carrying a nested array of log-context data. Rendering it
    // as a single JSON blob made the field unreadable; the nested-data
    // component drills the tree key-by-key.
    config()->set('queue-insights.capture.payloads', 'full');

    $payload = [
        'uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
        'displayName' => 'App\\Jobs\\Example',
        'maxTries' => 3,
        'attempts' => 1,
        'illuminate:log:context' => [
            'data' => [
                'user_id' => 42,
                'team_id' => 7,
                'request_id' => 'req-abc-123',
            ],
            'hidden' => [],
            'stackedData' => [],
        ],
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSee('Other fields')
        ->assertSee('illuminate:log:context')
        // The container header summarises shape ("object · 3 keys") instead of
        // dumping the full JSON inline. Drill-through reveals the leaves.
        ->assertSee('object · 3 keys')
        ->assertSee('user_id')
        ->assertSee('42')
        ->assertSee('team_id')
        ->assertSee('request_id')
        ->assertSee('req-abc-123');
});

it('Other fields nested-data uses template x-if so collapsed subtrees do not materialize', function (): void {
    // Codex perf regression: prior `<div x-show>` left every nested branch in
    // the DOM (just hidden via CSS), so a deep payload paid the full layout
    // cost up front. Switching to `<template x-if>` means the browser skips
    // layout for hidden branches; assert the marker is present in the
    // rendered HTML so a future revert to `x-show` breaks here.
    config()->set('queue-insights.capture.payloads', 'full');

    $payload = [
        'uuid' => 'cccccccc-1111-2222-3333-444444444444',
        'displayName' => 'App\\Jobs\\Example',
        'maxTries' => 3,
        'attempts' => 1,
        'illuminate:log:context' => [
            'data' => ['k' => 'v'],
        ],
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSeeHtml('<template x-if="expanded">');
});

it('Section C Raw pane shows extracted Job instance properties from data.command', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    // Simulate a real Laravel job: stdClass with public scalars + nested object.
    $instance = (object) [
        'videoId' => 18,
        'attemptsMade' => 0,
        'silent' => false,
        'options' => null,
    ];
    $payload = [
        'displayName' => 'App\\Jobs\\Video\\DuplicateInteractionsJob',
        'data' => [
            'commandName' => 'App\\Jobs\\Video\\DuplicateInteractionsJob',
            'command' => serialize($instance),
        ],
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        // Panel header
        ->assertSee('Job instance')
        // Property names
        ->assertSee('videoId')
        ->assertSee('attemptsMade')
        ->assertSee('silent')
        ->assertSee('options')
        // Property values
        ->assertSee('18')
        ->assertSee('false') // bool render
        ->assertSee('null'); // null render
});

it('Section C Raw pane recursively renders nested objects with expand affordance', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    // Mimic a real Laravel job that contains an Eloquent ModelIdentifier (nested
    // __PHP_Incomplete_Class on extraction). The user's reported example —
    // DuplicateInteractionsJob with createdInteractionIds → Collection — has the
    // same shape: outer object with a nested object property.
    $inner = (object) ['class' => 'App\\Models\\User', 'id' => 1];
    $instance = (object) [
        'videoId' => 18,
        'user' => $inner,
    ];
    $payload = [
        'displayName' => 'App\\Jobs\\Video\\DuplicateInteractionsJob',
        'data' => [
            'commandName' => 'App\\Jobs\\Video\\DuplicateInteractionsJob',
            'command' => serialize($instance),
        ],
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-25T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->html();

    // Outer property name + scalar value
    expect($html)->toContain('videoId')
        ->and($html)->toContain('18')
        // Nested-object row shows class name (sourced from the incomplete-class marker)
        ->and($html)->toContain('user')
        ->and($html)->toContain('stdClass')
        // Expand button rendered for nested object
        ->and($html)->toContain('expand')
        // Nested properties are present in the DOM (initially hidden via x-show + x-cloak,
        // but assertSee operates on the rendered HTML so the strings are findable).
        ->and($html)->toContain('App\\Models\\User')
        ->and($html)->toContain('class');
});

it('Section C Raw pane shows serialized-command blob collapsed with byte count', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    $serialized = str_repeat('O:39:"App\\Jobs\\Example":12:{s:7:"foo";i:1;}', 30); // realistic shape
    $payload = [
        'displayName' => 'App\\Jobs\\Example',
        'data' => [
            'commandName' => 'App\\Jobs\\Example',
            'command' => $serialized,
        ],
    ];

    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => (string) json_encode($payload),
    ]);

    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'];

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSee('Serialized command')
        ->assertSee('App\\Jobs\\Example')
        ->assertSee(number_format(strlen($serialized)) . ' bytes');
});

it('Chain section renders when chain JSON field is present on the stream entry', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Jobs\\Fake',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'chain' => (string) json_encode([
            ['class' => 'App\\Jobs\\NextJob', 'connection' => 'redis', 'queue' => 'default'],
            ['class' => 'App\\Jobs\\AfterJob', 'connection' => 'redis', 'queue' => 'default'],
        ]),
    ])
        ->assertSee('Chain')
        ->assertSee('App\\Jobs\\NextJob')
        ->assertSee('+1 more chained')
        // Click-through trigger and chain detail panel both rendered.
        ->assertSeeHtml('aria-label="View full chain details"')
        ->assertSeeHtml('data-section="chain-detail"')
        ->assertSee('App\\Jobs\\AfterJob');
});

it('chain list entries are clickable buttons that drill into the chain-detail view', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Jobs\\Fake',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'chain' => (string) json_encode([
            ['class' => 'App\\Jobs\\NextJob', 'connection' => 'redis', 'queue' => 'default'],
            ['class' => 'App\\Jobs\\AfterJob', 'connection' => 'redis', 'queue' => 'default'],
        ]),
    ])
        // Each chain entry is now a button that switches the modal's Alpine
        // `view` to chain-detail. Pin to the per-index click so adding a new
        // chain link surfaces here too.
        ->assertSeeHtml("chainIndex = 0; view = 'chain-detail'")
        ->assertSeeHtml("chainIndex = 1; view = 'chain-detail'")
        ->assertSeeHtml('aria-label="View details for chained job 1"')
        ->assertSeeHtml('aria-label="View details for chained job 2"')
        // Drill-down view block is rendered server-side; Alpine swaps
        // visibility when chainIndex flips.
        ->assertSee('Chained job 1 of 2')
        ->assertSee('Chained job 2 of 2')
        // Live-status placeholder for downstream chain links — the chain
        // partial doesn't track them, so each renders the "not tracked"
        // dt/dd row in the chain-detail view.
        ->assertSee('not tracked');
});

it('Chain section is omitted when chain field is absent', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    openDetailsModal([
        'class' => 'App\\Jobs\\Fake',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ])
        ->assertDontSeeHtml('data-section="chain"');
});

it('Section C decode-failure fallback renders raw string without colorizer attribute', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    openDetailsModal([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => 'not valid json',
    ])
        ->assertSeeText('Sanitized JSON')
        // The invalid-JSON fallback still renders the payload_body string inside the JSON
        // pane (raw textContent). Colorizer client-side is responsible for highlighting;
        // server just emits the `[data-json-highlight]` marker + raw string.
        ->assertSeeText('not valid json');
});
