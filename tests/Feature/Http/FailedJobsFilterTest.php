<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\FailedJobFilters;

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
    Livewire::withQueryParams(['fc' => 'redis', 'fq' => 'video', 'fk' => 'App\\Foo']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSet('filterConnection', 'redis')
        ->assertSet('filterQueue', 'video')
        ->assertSet('filterClass', 'App\\Foo');
});

it('clearFailedFilters resets every filter prop to empty', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterConnection', 'redis')
        ->set('filterQueue', 'video')
        ->set('filterClass', 'App\\Foo')
        ->set('filterFrom', '2026-04-01')
        ->set('filterTo', '2026-04-30')
        ->call('clearFailedFilters')
        ->assertSet('filterConnection', '')
        ->assertSet('filterQueue', '')
        ->assertSet('filterClass', '')
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
