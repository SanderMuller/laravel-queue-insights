<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;

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

it('failed modal renders the class FQCN title with a faded namespace + bold leaf', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        // Namespace renders in a faded span, base-class leaf in a bold one —
        // same treatment as the completed-jobs details modal title.
        ->assertSeeHtml('<span class="text-gray-400 dark:text-gray-500">App\\Jobs\\</span>')
        ->assertSeeHtml('<span class="font-semibold text-gray-900 dark:text-gray-100">SendEmail</span>');
});

it('failed modal renders the job-config hero with pills + tags from the payload', function (): void {
    $id = seedFailedJob([
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendEmail',
            'maxTries' => 3,
            'timeout' => 60,
            'backoff' => [1, 5, 10],
            'tags' => ['App\\Models\\User:42'],
            'attempts' => 3,
        ]),
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSee('Job Config')
        ->assertSee('maxTries')
        ->assertSee('timeout')
        ->assertSee('60 s')
        ->assertSee('backoff')
        ->assertSee('1, 5, 10 s')
        ->assertSee('tags')
        ->assertSee('App\\Models\\User:42');
});

it('failed modal payload section has Structured + Sanitized JSON underline tabs', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        // Default Structured tab.
        ->assertSeeHtml('id="qi-failed-tab-raw"')
        ->assertSeeHtml('id="qi-failed-tab-json"')
        ->assertSee('Structured')
        ->assertSee('Sanitized JSON')
        // openFailed resets the shared payloadTab to the default.
        ->assertSet('payloadTab', 'raw')
        // Flip to JSON — the colorizer-hooked pre carries the highlight attr.
        ->call('setPayloadTab', 'json')
        ->assertSeeHtml('data-json-highlight')
        ->assertSeeHtml('id="qi-failed-panel-json"');
});

it('Esc keydown handler is wired to closeFailed on the failed modal', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSeeHtml('x-on:keydown.escape.window')
        ->assertSeeHtml('$wire.closeFailed()');
});

it('inert wrapper is driven by a server-computed hasOpenModal flag', function (): void {
    // Codex regression: the previous predicate routed off raw selection ids,
    // which could freeze the dashboard when an id was set but no modal was
    // actually mounted. Now driven by `$hasOpenModal` — the server computes
    // it from the same booleans that gate modal mounts.
    Livewire::test(QueueInsightsDashboard::class)
        // No modal open by default → inert is literal `false`.
        ->assertSeeHtml('x-bind:inert="false"');

    $id = seedFailedJob();
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        // Failed modal is mounted → inert flips to `true`.
        ->assertSeeHtml('x-bind:inert="true"');
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

it('failed modal renders Chain section when payload.data.command carries a non-empty chained array', function (): void {
    $nextClass = 'App\\Jobs\\NextJob';
    $afterClass = 'App\\Jobs\\AnotherJob';
    $outerClass = 'App\\Jobs\\Fake';
    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';
    $afterJob = 'O:' . strlen($afterClass) . ':"' . $afterClass . '":0:{}';
    $command = 'O:' . strlen($outerClass) . ':"' . $outerClass . '":3:{'
        . "s:10:\"\x00*\x00chained\";a:2:{i:0;s:" . strlen($nextJob) . ':"' . $nextJob . '";i:1;s:' . strlen($afterJob) . ':"' . $afterJob . '";}'
        . "s:18:\"\x00*\x00chainConnection\";s:5:\"redis\";"
        . "s:13:\"\x00*\x00chainQueue\";s:7:\"default\";"
        . '}';

    $id = seedFailedJob([
        'payload' => json_encode([
            'displayName' => $outerClass,
            'maxTries' => 1,
            'attempts' => 1,
            'data' => ['commandName' => $outerClass, 'command' => $command],
        ]),
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSee('Chain')
        ->assertSee('App\\Jobs\\NextJob')
        ->assertSee('+1 more chained');
});

it('failed modal chain entries are clickable buttons that drill into chain-detail', function (): void {
    // Same payload shape as the previous test, just asserting the new
    // drill-down affordance.
    $nextClass = 'App\\Jobs\\NextChainJob';
    $afterClass = 'App\\Jobs\\AnotherChainJob';
    $outerClass = 'App\\Jobs\\FakeOuter';
    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';
    $afterJob = 'O:' . strlen($afterClass) . ':"' . $afterClass . '":0:{}';
    $command = 'O:' . strlen($outerClass) . ':"' . $outerClass . '":3:{'
        . "s:10:\"\x00*\x00chained\";a:2:{i:0;s:" . strlen($nextJob) . ':"' . $nextJob . '";i:1;s:' . strlen($afterJob) . ':"' . $afterJob . '";}'
        . "s:18:\"\x00*\x00chainConnection\";s:5:\"redis\";"
        . "s:13:\"\x00*\x00chainQueue\";s:7:\"default\";"
        . '}';

    $id = seedFailedJob([
        'payload' => json_encode([
            'displayName' => $outerClass,
            'maxTries' => 1,
            'attempts' => 1,
            'data' => ['commandName' => $outerClass, 'command' => $command],
        ]),
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertSeeHtml("chainIndex = 0; view = 'chain-detail'")
        ->assertSeeHtml("chainIndex = 1; view = 'chain-detail'")
        ->assertSeeHtml('aria-label="View details for chained job 1"')
        // Drill-down detail blocks render server-side for both indices.
        ->assertSee('Chained job 1 of 2')
        ->assertSee('Chained job 2 of 2');
});

it('failed modal omits Chain section when no chained array is present', function (): void {
    $id = seedFailedJob();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openFailed', $id)
        ->assertDontSeeHtml('data-section="chain"');
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
