<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\CompletedRowFilter;
use SanderMuller\QueueInsights\Support\FailedJobFilters;
use SanderMuller\QueueInsights\Tests\Support\FailedUuidCollectorProbe;

beforeEach(function (): void {
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
 * @param  array<string, mixed>  $overrides
 */
function seedFailedFilterRow(array $overrides = []): int
{
    $payloadOverride = null;
    if (array_key_exists('payload', $overrides) && is_array($overrides['payload'])) {
        $payloadOverride = $overrides['payload'];
        unset($overrides['payload']);
    }

    $payload = $payloadOverride ?? [
        'displayName' => 'App\\Jobs\\SendEmail',
        'maxTries' => 3,
        'attempts' => 1,
    ];

    /** @var array<string, mixed> $row */
    $row = array_merge([
        'uuid' => 'uuid-' . Str::random(8),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode($payload),
        'exception' => 'Throwable: x',
        'failed_at' => '2026-04-24 12:00:00',
    ], $overrides);

    return (int) DB::table('failed_jobs')->insertGetId($row);
}

it('returns all failed rows when filters are empty', function (): void {
    seedFailedFilterRow();
    seedFailedFilterRow();

    $rows = resolve(QueueInsights::class)->recentFailed();

    expect($rows)->toHaveCount(2);
});

it('filters by connection', function (): void {
    seedFailedFilterRow(['connection' => 'redis']);
    seedFailedFilterRow(['connection' => 'sqs']);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(connection: 'sqs'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['connection'])->toBe('sqs');
});

it('filters by queue', function (): void {
    seedFailedFilterRow(['queue' => 'default']);
    seedFailedFilterRow(['queue' => 'video']);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(queue: 'video'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['queue'])->toBe('video');
});

it('filters by class FQCN with prefix substring on the JSON payload', function (): void {
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\SendEmail']]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\SendEmailReceipt']]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Other']]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(class: 'App\\Jobs\\SendEmail'));

    expect($rows)->toHaveCount(2);
});

it('class filter matches FQCNs containing backslashes (cross-DB LIKE regression)', function (): void {
    // Bug report: dropdown picks landed on 0 results on MySQL even when matches
    // existed. Root cause: addslashes + default `\` LIKE escape consumed the
    // doubled backslash back to a single, which never matched the JSON column's
    // `\\` form. ESCAPE '|' + json_encode round-trip fixes it. Test runs on
    // SQLite where the prior code happened to work — assertion still pins the
    // expected 1-result match so a regression to the broken pattern fails here.
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\BackslashedJob']]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\OtherJob']]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(class: 'App\\Jobs\\BackslashedJob'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['payload'])->toContain('App\\\\Jobs\\\\BackslashedJob');
});

it('class filter is case-insensitive so deep-linked URLs with mismatched casing still match', function (): void {
    // Codex regression: the URL-bound `?fk=` prop accepts arbitrary casing.
    // Without LOWER() on both sides, PostgreSQL's case-sensitive LIKE would
    // silently miss while MySQL/SQLite matched — DB-dependent behaviour for
    // user input. Normalising both sides keeps the match set stable.
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\SendEmail']]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(class: 'app\\jobs\\sendemail'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['payload'])->toContain('SendEmail');
});

it('class filter escapes LIKE wildcards in user input', function (): void {
    // Defence: a user-supplied class name containing `%` or `_` (rare but
    // possible via deep-linked URL with crafted ?fk=…) must not become a
    // wildcard. Seed two rows: one whose displayName matches the literal
    // wildcard input, one that would only match if the wildcard escaped.
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\With_Underscore']]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\WithXUnderscore']]);

    // `_` is the LIKE single-char wildcard. Without escaping, both rows match.
    // With escaping, only the literal underscore row matches.
    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(class: 'App\\Jobs\\With_Underscore'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['payload'])->toContain('With_Underscore');
});

it('class filter does not match substring inside an unrelated payload field', function (): void {
    // Regression: substring match on the raw JSON column without anchoring would
    // false-match if "App\Foo" appeared inside, say, an argument value. The
    // anchored `"displayName":"…` prefix in QueueInsights::recentFailed is what
    // prevents this.
    seedFailedFilterRow([
        'payload' => [
            'displayName' => 'App\\Jobs\\Other',
            'data' => ['note' => 'mentions App\\Jobs\\SendEmail in passing'],
        ],
    ]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(class: 'App\\Jobs\\SendEmail'));

    expect($rows)
        ->toBeEmpty();
});

it('filters by failed_at date range (inclusive bounds)', function (): void {
    seedFailedFilterRow(['failed_at' => '2026-04-20 10:00:00']);
    seedFailedFilterRow(['failed_at' => '2026-04-22 23:59:59']);
    seedFailedFilterRow(['failed_at' => '2026-04-25 00:00:00']);

    $rows = resolve(QueueInsights::class)
        ->recentFailed(50, new FailedJobFilters(from: '2026-04-22', to: '2026-04-22'));

    expect($rows)->toHaveCount(1);
});

it('combines multiple filters with AND semantics', function (): void {
    seedFailedFilterRow(['connection' => 'redis', 'queue' => 'default']);
    seedFailedFilterRow(['connection' => 'redis', 'queue' => 'video']);
    seedFailedFilterRow(['connection' => 'sqs', 'queue' => 'default']);

    $rows = resolve(QueueInsights::class)
        ->recentFailed(50, new FailedJobFilters(connection: 'redis', queue: 'video'));

    expect($rows)->toHaveCount(1);
});

it('Livewire #[Url] props bind via the configured query-string keys', function (): void {
    Livewire::withQueryParams(['fc' => 'redis', 'fq' => 'video', 'ck' => 'App\\Foo']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('filterConnection', 'redis')
        ->assertSet('filterQueue', 'video')
        ->assertSet('selectedClass', 'App\\Foo');
});

it('selectQueue stores the canonical key and routes failed filter to that queue', function (): void {
    seedFailedFilterRow(['connection' => 'redis', 'queue' => 'default']);
    seedFailedFilterRow(['connection' => 'redis', 'queue' => 'high']);

    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('selectQueue', 'redis', 'high')
        ->assertSet('selectedQueue', 'redis:high');

    $filters = $component->instance()->buildFailedFilters();

    expect($filters->connection)->toBe('redis')
        ->and($filters->queue)->toBe('high');
});

it('selectedQueue overrides per-pane filterConnection/filterQueue when set', function (): void {
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('filterConnection', 'sqs')
        ->set('filterQueue', 'default')
        ->call('selectQueue', 'redis', 'video');

    $filters = $component->instance()->buildFailedFilters();

    expect($filters->connection)->toBe('redis')
        ->and($filters->queue)->toBe('video');
});

it('clearSelectedQueue resets selectedQueue and pages', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectQueue', 'redis', 'high')
        ->set('failedPage', 5)
        ->call('clearSelectedQueue')
        ->assertSet('selectedQueue', '')
        ->assertSet('failedPage', 1);
});

it('selectQueue toggles — clicking the already-selected queue clears the scope', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectQueue', 'redis', 'high')
        ->assertSet('selectedQueue', 'redis:high')
        ->call('selectQueue', 'redis', 'high')
        ->assertSet('selectedQueue', '');
});

it('selectClass toggles — clicking the already-selected class clears the scope', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectClass', 'App\\Foo')
        ->assertSet('selectedClass', 'App\\Foo')
        ->call('selectClass', 'App\\Foo')
        ->assertSet('selectedClass', null);
});

it('selectQueue ignores empty connection or queue', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->call('selectQueue', '', 'high')
        ->assertSet('selectedQueue', '')
        ->call('selectQueue', 'redis', '')
        ->assertSet('selectedQueue', '');
});

it('Livewire #[Url] binds selectedQueue via the qk query-string key', function (): void {
    Livewire::withQueryParams(['qk' => 'redis:video']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('selectedQueue', 'redis:video');
});

it('selectedFailed DB fallback rejects rows outside the path-level scope', function (): void {
    // Seed a silenced row on `sqs` connection — won't appear in the visible
    // failed list (FailedJobFilters strips silenced classes by default).
    config()->set('queue-insights.silenced', ['App\\Jobs\\NoisyVendor']);
    $id = seedFailedFilterRow([
        'connection' => 'sqs',
        'queue' => 'reports',
        'payload' => ['displayName' => 'App\\Jobs\\NoisyVendor', 'maxTries' => 3, 'attempts' => 1],
    ]);

    // Operator is path-scoped to `redis`. A deep-linked / forged
    // selectedFailedId pointing at the `sqs` row must NOT cross the scope.
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('scopeConnection', 'redis')
        ->call('openFailed', $id);

    expect($component->viewData('selectedFailed'))->toBeNull();
});

it('selectedFailed DB fallback rejects rows outside the selectedQueue scope', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\NoisyVendor']);
    $id = seedFailedFilterRow([
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => ['displayName' => 'App\\Jobs\\NoisyVendor', 'maxTries' => 3, 'attempts' => 1],
    ]);

    // selectedQueue scoped to `redis:video`; a `redis:default` failed row
    // must not leak through the silenced-row fallback.
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('selectQueue', 'redis', 'video')
        ->call('openFailed', $id);

    expect($component->viewData('selectedFailed'))->toBeNull();
});

it('selectedFailed DB fallback opens silenced rows that match the active scope', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\NoisyVendor']);
    $id = seedFailedFilterRow([
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => ['displayName' => 'App\\Jobs\\NoisyVendor', 'maxTries' => 3, 'attempts' => 1],
    ]);

    // No scope — silenced row should still resolve via DB fallback so the
    // Silenced tab's click-through opens the modal.
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id);

    expect($component->viewData('selectedFailed'))->not->toBeNull();
});

it('clearFailedFilters resets every filter prop to empty', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterConnection', 'redis')
        ->set('filterQueue', 'video')
        ->set('selectedClass', 'App\\Foo')
        ->set('filterFrom', '2026-04-01')
        ->set('filterTo', '2026-04-30')
        ->call('clearFailedFilters')
        ->assertSet('filterConnection', '')
        ->assertSet('filterQueue', '')
        ->assertSet('selectedClass', null)
        ->assertSet('filterFrom', '')
        ->assertSet('filterTo', '');
});

it('renders the empty-filtered message when no rows match', function (): void {
    seedFailedFilterRow(['connection' => 'redis']);

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterConnection', 'sqs')
        ->assertSee('No failed jobs match the current filters');
});

it('shows the `filtered` badge on the heading when any filter is set', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterConnection', 'redis')
        ->assertSeeText('filtered');
});

it('hides silenced-class failed rows by default', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Noisy', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Quiet', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50);
    $payload = $rows[0]['payload'] ?? null;

    expect($rows)->toHaveCount(1)
        ->and(is_string($payload) ? $payload : '')->toContain('App\\\\Jobs\\\\Quiet');
});

it('reveals silenced-class failed rows when includeSilenced=true', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Noisy', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Quiet', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(includeSilenced: true));

    expect($rows)->toHaveCount(2);
});

it('hides rows matching a silenced_patterns glob', function (): void {
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Reports\\Daily', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Reports\\Weekly', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Other', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50);
    $payload = $rows[0]['payload'] ?? null;

    expect($rows)->toHaveCount(1)
        ->and(is_string($payload) ? $payload : '')->toContain('App\\\\Jobs\\\\Other');
});

it('silenced_patterns glob escape preserves underscores between wildcards', function (): void {
    // Pattern `App\\Jobs\\With_*` should match a class whose segment after
    // `With` starts with an underscore (literal). It must NOT match
    // `App\\Jobs\\WithXFoo` even though a naive regex translation could
    // collapse `_` → `.`. The DisplayNamePayloadMatch::patternFromGlob
    // splits on `*` and escapes each segment — `_` becomes `|_` so the
    // SQL LIKE keeps it literal.
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\With_*']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\With_Foo', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\WithXFoo', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50);
    $payload = $rows[0]['payload'] ?? null;

    expect($rows)->toHaveCount(1)
        ->and(is_string($payload) ? $payload : '')->toContain('App\\\\Jobs\\\\WithXFoo');
});

it('silenced_patterns are revealed when includeSilenced=true', function (): void {
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Reports\\Daily', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\Other', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50, new FailedJobFilters(includeSilenced: true));

    expect($rows)->toHaveCount(2);
});

it('silenced exclusion escapes wildcards so a class containing _ does not over-hide', function (): void {
    // The class `App\Jobs\With_Underscore` is silenced. A class
    // `App\Jobs\WithXUnderscore` (X = arbitrary char) would match a naive
    // unescaped LIKE pattern of `%with_underscore%` and get hidden too.
    // The escape discipline mirrors `applyFailedJobFilters`'s include-side.
    config()->set('queue-insights.silenced', ['App\\Jobs\\With_Underscore']);

    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\With_Underscore', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['payload' => ['displayName' => 'App\\Jobs\\WithXUnderscore', 'maxTries' => 3, 'attempts' => 1]]);

    $rows = resolve(QueueInsights::class)->recentFailed(50);
    $payload = $rows[0]['payload'] ?? null;

    expect($rows)->toHaveCount(1)
        ->and(is_string($payload) ? $payload : '')->toContain('App\\\\Jobs\\\\WithXUnderscore');
});

it('clearFailedFilters resets includeSilenced back to false', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('includeSilenced', true)
        ->call('clearFailedFilters')
        ->assertSet('includeSilenced', false);
});

it('toggling includeSilenced resets the failed page cursor', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('failedPage', 4)
        ->set('includeSilenced', true)
        ->assertSet('failedPage', 1);
});

it('Livewire #[Url] binds includeSilenced via the fs query-string key', function (): void {
    Livewire::withQueryParams(['fs' => '1']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('includeSilenced', true);
});

it('buildFailedFilters propagates includeSilenced into the DTO', function (): void {
    /** @var QueueInsightsDashboard $component */
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->set('includeSilenced', true)
        ->instance();

    expect($component->buildFailedFilters()->includeSilenced)->toBeTrue();
});

it('CompletedRowFilter drops silenced-class rows by default and keeps them when includeSilenced=true', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);
    app()->forgetScopedInstances();

    $rows = [
        ['class' => 'App\\Jobs\\Noisy', 'connection' => 'redis', 'queue' => 'webhooks'],
        ['class' => 'App\\Jobs\\Quiet', 'connection' => 'redis', 'queue' => 'mail'],
    ];

    $defaultFilter = new CompletedRowFilter();
    $defaultClasses = array_map(static fn (array $r): string => is_string($r['class'] ?? null) ? $r['class'] : '', $defaultFilter->apply($rows));
    expect($defaultClasses)->toBe(['App\\Jobs\\Quiet']);

    $revealFilter = new CompletedRowFilter(includeSilenced: true);
    $revealClasses = array_map(static fn (array $r): string => is_string($r['class'] ?? null) ? $r['class'] : '', $revealFilter->apply($rows));
    expect($revealClasses)->toBe(['App\\Jobs\\Noisy', 'App\\Jobs\\Quiet']);
});

it('clearCompletedFilters resets completedIncludeSilenced back to false', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('completedIncludeSilenced', true)
        ->call('clearCompletedFilters')
        ->assertSet('completedIncludeSilenced', false);
});

it('toggling completedIncludeSilenced resets the completed page cursor', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('completedPage', 4)
        ->set('completedIncludeSilenced', true)
        ->assertSet('completedPage', 1);
});

it('Livewire #[Url] binds completedIncludeSilenced via the cs query-string key', function (): void {
    Livewire::withQueryParams(['cs' => '1']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('completedIncludeSilenced', true);
});

it('FailedJobUuidCollector inherits the silenced exclusion (bulk-retry parity)', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);
    app()->forgetScopedInstances();

    seedFailedFilterRow(['uuid' => 'uuid-noisy', 'payload' => ['displayName' => 'App\\Jobs\\Noisy', 'maxTries' => 3, 'attempts' => 1]]);
    seedFailedFilterRow(['uuid' => 'uuid-quiet', 'payload' => ['displayName' => 'App\\Jobs\\Quiet', 'maxTries' => 3, 'attempts' => 1]]);

    $defaultUuids = FailedUuidCollectorProbe::collect(new FailedJobFilters());
    $revealedUuids = FailedUuidCollectorProbe::collect(new FailedJobFilters(includeSilenced: true));

    expect($defaultUuids)->toBe(['uuid-quiet'])
        ->and($revealedUuids)->toContain('uuid-noisy')
        ->and($revealedUuids)->toContain('uuid-quiet');
});
