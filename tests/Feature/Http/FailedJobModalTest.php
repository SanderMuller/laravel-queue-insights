<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;

beforeEach(function (): void {
    // Replicate Laravel's default failed_jobs schema. Orchestra's :memory: SQLite
    // comes up empty, so we provision just the columns QueueInsights::recentFailed
    // and the failed-job modal read from.
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
function seedFailedJob(array $overrides = []): int
{
    /** @var array<string, mixed> $row */
    $row = array_merge([
        'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendEmail',
            'maxTries' => 3,
            'attempts' => 3,
        ]),
        'exception' => "RuntimeException: Something broke\n#0 /app/Jobs/SendEmail.php(42): send()\n#1 {main}",
        'failed_at' => '2026-04-24 12:00:00',
    ], $overrides);

    return (int) DB::table('failed_jobs')->insertGetId($row);
}

it('failed table row is clickable and wired to openFailed with the row id', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml("wire:click=\"openFailed({$id})\"")
        ->assertSeeHtml('role="button"')
        ->assertSee('Open');
});

it('openFailed populates $selectedFailedId and closeFailed clears it', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSet('selectedFailedId', $id)
        ->call('closeFailed')
        ->assertSet('selectedFailedId', null);
});

it('failed modal renders identity hero, metrics row, and exception sections', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-labelledby="qi-failed-modal-title"')
        ->assertSee('Failed job')
        ->assertSee('App\\Jobs\\SendEmail')
        ->assertSee('Connection')
        ->assertSee('redis')
        ->assertSee('Queue')
        ->assertSee('default')
        ->assertSee('Attempts')
        ->assertSee('of 3')
        ->assertSee('Failed at')
        ->assertSee('2026-04-24 12:00:00')
        ->assertSee('Row ID')
        ->assertSee('UUID')
        ->assertSee('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        // Exception + stack trace are now rendered via the parsed stack-trace component:
        // the header class and message are in separate elements, frame rows are list items.
        ->assertSee('Exception')
        ->assertSee('RuntimeException')
        ->assertSee('Something broke')
        // First frame's file path (before the line number) is mono-rendered on its own line.
        ->assertSee('/app/Jobs/SendEmail.php')
        ->assertSee('Payload');
});

it('failed modal handles missing payload / exception gracefully', function (): void {
    $id = seedFailedJob([
        'payload' => 'not-valid-json',
        'exception' => '',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSee('Failed job')
        // displayName derived from payload JSON; falls back to `—` when decode fails.
        ->assertSeeHtml('>—<')
        // Empty exception → no Stack trace section rendered.
        ->assertDontSee('Stack trace');
});

it('Esc keydown handler is wired to closeFailed on the failed modal', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSeeHtml('x-on:keydown.escape.window')
        ->assertSeeHtml('$wire.closeFailed()');
});

it('inert wrapper triggers on either selectedPayloadId or selectedFailedId', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('$wire.selectedPayloadId !== null || $wire.selectedFailedId !== null');
});

it('stack trace component splits app vs vendor frames and offers a vendor toggle', function (): void {
    $id = seedFailedJob([
        'exception' => "RuntimeException: Something broke\n"
            . "#0 /app/Jobs/SendEmail.php(42): App\\Jobs\\SendEmail->send()\n"
            . "#1 /vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(124): handle()\n"
            . '#2 {main}',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        // App frame visible, vendor frame marked with the badge.
        ->assertSee('/app/Jobs/SendEmail.php')
        ->assertSeeHtml('>vendor</span>')
        // Vendor toggle button — 1 vendor frame here.
        ->assertSee('Show 1 vendor frame');
});

it('stack trace component handles malformed exception strings gracefully', function (): void {
    $id = seedFailedJob([
        'exception' => 'just a one-liner without a trace',
    ]);

    // The header parses; no frames render but the section still appears.
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSee('just a one-liner without a trace')
        ->assertSee('No stack frames available');
});

it('renders Copy markdown + Copy stack-trace buttons with hidden source nodes', function (): void {
    $id = seedFailedJob();

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSee('Copy markdown')
        ->html();

    // Stack-trace copy button + matching hidden <pre> source.
    expect($html)
        ->toContain('data-qi-copy-target="qi-failed-stack"')
        ->toContain('id="qi-failed-stack"')
        ->toContain('data-qi-copy-target="qi-failed-markdown"')
        ->toContain('id="qi-failed-markdown"');

    // Markdown source contains the structured fields the AI agent would need.
    expect($html)
        ->toContain('# Failed job')
        ->toContain('**Class:** App\\Jobs\\SendEmail')
        ->toContain('**Connection:** redis')
        ->toContain('**Queue:** default')
        ->toContain('**Attempts:** 3 of 3')
        ->toContain('## Exception')
        ->toContain('## Payload');
});

it('omits the stack-trace copy button + source when no exception is recorded', function (): void {
    $id = seedFailedJob(['exception' => '']);

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->html();

    // Markdown export still renders (header + identity), but the Stack section /
    // hidden <pre> only exist when the exception column is populated.
    expect($html)
        ->toContain('id="qi-failed-markdown"')
        ->not->toContain('id="qi-failed-stack"')
        ->not->toContain('data-qi-copy-target="qi-failed-stack"');
});

it('Markdown export survives exception text that contains literal triple backticks', function (): void {
    // Stack traces / payloads can legitimately include ``` (SQL, shell, prior
    // markdown). A naive ``` fence would close early and corrupt the export.
    // This test pins the dynamic-fence behaviour from the codex review.
    $id = seedFailedJob([
        'exception' => "RuntimeException: bad sql\n```\nSELECT * FROM users\n```\n#0 /app/Jobs/SendEmail.php(42)",
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendEmail',
            'attempts' => 1,
            'maxTries' => 3,
            'data' => ['snippet' => '```php echo "hi"; ```'],
        ]),
    ]);

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->html();

    // Pull the markdown out of the hidden <pre> (escape it back since Blade
    // HTML-encodes the body inside `{{ }}`). Helper centralises the match-or-fail
    // dance so each call narrows the captured group for the type-checker.
    $captureGroup = static function (string $pattern, string $subject): string {
        $matched = preg_match($pattern, $subject, $captures);
        expect($matched)->toBe(1)
            ->and($captures)
            ->toHaveKey(1);

        return is_string($captures[1] ?? null) ? $captures[1] : '';
    };

    $markdown = html_entity_decode(
        $captureGroup('/id="qi-failed-markdown"[^>]*>(.+?)<\/pre>/s', $html),
        ENT_QUOTES | ENT_HTML5,
    );

    // The opening fence must be longer than any backtick run inside the body.
    // A 3-backtick run in the exception forces ≥4 backtick fences for both blocks.
    expect(strlen($captureGroup('/\n(`+)\nRuntimeException: bad sql\n/', $markdown)))->toBeGreaterThanOrEqual(4);
    expect(strlen($captureGroup('/\n(`+)json\n/', $markdown)))->toBeGreaterThanOrEqual(4);
});

it('renders 100% fail rate when a class has only failures and zero successes', function (): void {
    // Regression: prior code computed `$processed > 0 ? failed/(processed+failed) : 0`,
    // which rendered "0.0% fail rate" for all-failed classes — hiding the worst-case
    // operational signal during triage (codex review).
    config()->set('queue-insights.key_prefix', 'qmtest:');
    Redis::connection('default')->command('flushdb', []);

    $r = Redis::connection('default');
    $thisHour = now('UTC')->format('YmdH');
    $r->command('zadd', [KeyPrefix::make('classes'), now()->getTimestamp(), 'App\\OnlyFails']);
    $r->command('set', [KeyPrefix::make("failed:App\\OnlyFails:{$thisHour}"), '5']);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('100.0% fail rate');
});
