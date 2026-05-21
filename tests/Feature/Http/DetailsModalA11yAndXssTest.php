<?php declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
});

/**
 * @param  array<string, string>  $fields
 */
function openModalWith(array $fields): string
{
    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), $fields);
    $completed = resolve(QueueInsights::class)->recentCompleted(10);
    $id = $completed[0]['_id'] ?? null;
    expect($id)->toBeString();

    return (string) $id;
}

// ---------- Accessibility ----------

it('modal backdrop carries role=dialog + aria-modal + aria-labelledby', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('aria-labelledby="qi-modal-title"')
        ->assertSeeHtml('id="qi-modal-title"');
});

it('tab toggle carries role=tab + aria-selected reflects payloadTab state', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => '{"foo":"bar"}',
    ]);

    // Default: Raw tab selected (Resolved Q #18).
    $component = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id);

    $html = $component->html();
    expect($html)->toContain('id="qi-tab-json"')
        ->and($html)->toContain('id="qi-tab-raw"')
        ->and($html)->toContain('aria-controls="qi-panel-json"')
        ->and($html)->toContain('aria-controls="qi-panel-raw"');

    // Raw tab aria-selected="true" on default; JSON tab false.
    expect($html)->toMatch('/id="qi-tab-raw"[^>]*aria-selected="true"/');
    expect($html)->toMatch('/id="qi-tab-json"[^>]*aria-selected="false"/');

    // Flip to JSON — assertions invert.
    $component->call('setPayloadTab', 'json');
    $html2 = $component->html();
    expect($html2)->toMatch('/id="qi-tab-json"[^>]*aria-selected="true"/')
        ->and($html2)->toMatch('/id="qi-tab-raw"[^>]*aria-selected="false"/');
});

it('dashboard content wrapper has qi-dashboard-content id for sibling-inert pattern', function (): void {
    Livewire::test(QueueInsightsDashboard::class)
        ->assertSeeHtml('id="qi-dashboard-content"')
        ->assertSeeHtml('x-bind:inert');
});

it('modal content wrapper uses Alpine @click.stop, not wire:click.stop', function (): void {
    // Regression: `wire:click.stop` (with no method value) compiled in Livewire 3 to
    // `$wire.()` which Alpine couldn't parse — fired on every click inside the modal.
    // Alpine's `@click.stop` is the correct value-less stopPropagation pattern.
    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ]);

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->html();

    expect($html)->not->toContain('wire:click.stop')
        ->and($html)->toContain('@click.stop');
});

it('Esc keydown handler is wired to closePayload', function (): void {
    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ]);

    // Alpine `x-on:keydown.escape.window` emits literal attribute text in the rendered
    // HTML — assert the wiring exists so a template refactor that drops Esc-close is
    // caught server-side without needing a browser.
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSeeHtml('x-on:keydown.escape.window')
        ->assertSeeHtml('$wire.closePayload()');
});

it('copy button carries aria-label', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');

    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->assertSeeHtml('aria-label="Copy stream id"')
        ->assertSeeHtml('aria-label="Close details modal"');
});

// ---------- XSS: Layer 1 (server-side Blade escape) ----------

it('DOM-contract: JSON pane carries [data-json-highlight] attribute', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => '{"foo":"bar"}',
    ]);

    // Default tab is Raw (Resolved Q #18) — flip to JSON to exercise the colorizer pane.
    Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->call('setPayloadTab', 'json')
        ->assertSeeHtml('data-json-highlight');
});

it('XSS layer 1 — hostile payload_body reaches JSON pane with < > entity-escaped by Blade', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    // Valid JSON containing a hostile string value — reaches the colorizer pane
    // (not the decode-failure fallback). Blade's {{ }} must entity-escape the
    // hostile content before it lands in the HTML attribute stream.
    $id = openModalWith([
        'class' => 'App\\Foo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'payload_body' => '{"foo": "<script>alert(1)</script>"}',
    ]);

    // Default tab is Raw; flip to JSON to land the hostile content in the colorizer pane.
    $html = Livewire::test(QueueInsightsDashboard::class)
        ->call('openPayload', $id)
        ->call('setPayloadTab', 'json')
        ->html();

    // Locate the JSON pane and assert what's inside it specifically — there's a
    // separate copy of `<script>` text in the colorizer's own JS source in the
    // layout view, which would false-positive a naive full-HTML contains check.
    // But since Livewire::test() renders just the component (not the layout),
    // the layout JS doesn't show up in $html. Straightforward assertions.
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

// ---------- XSS: Layer 2 (client-side colorizer sink execution) ----------

it('XSS layer 2 — hook body runs highlightJson + insertAdjacentHTML on hostile textContent', function (): void {
    // Extract highlightJson + the hook body from layouts/app.blade.php, run the full
    // sink path against a minimal DOM shim seeded with hostile textContent, assert
    // the final _html buffer contains entity-escaped text and no literal <script>.
    $layoutPath = __DIR__ . '/../../../resources/views/layouts/app.blade.php';
    expect($layoutPath)
        ->toBeFile();

    $source = file_get_contents($layoutPath);
    if ($source === false) {
        $this->fail('Could not read layouts/app.blade.php');

        return;
    }

    // Locate the two JS blocks we need. Extraction regex is deliberately simple —
    // it matches the function declarations verbatim so refactors that rename the
    // function or wrap it in a closure fail this assertion loudly.
    if (preg_match('/function highlightJson\(src\) \{(.*?)\n {8}\}/s', $source, $highlightMatch) !== 1) {
        $this->fail('Could not locate highlightJson function in layout — extraction regex needs updating');

        return;
    }

    if (preg_match('/function registerQueueInsightsHook\(\) \{(.*?)\n {8}\}/s', $source, $hookMatch) !== 1) {
        $this->fail('Could not locate registerQueueInsightsHook function in layout — extraction regex needs updating');

        return;
    }

    // Source-order sanity: escape-first must appear before token-wrapping in highlightJson.
    $escapeIdx = strpos($source, "replace(/&/g, '&amp;')");
    $tokenIdx = strpos($source, '("(?:\\\\.|[^"\\\\])*")');
    if ($escapeIdx === false || $tokenIdx === false) {
        $this->fail('Could not locate escape-chain or token-wrapping regex in highlightJson source');

        return;
    }

    expect($escapeIdx)->toBeLessThan($tokenIdx);

    // Two-mode Node probe — hard fail in CI, skip locally.
    $probe = new Process(['node', '--version']);
    $probe->run();
    if (! $probe->isSuccessful()) {
        if (getenv('CI') === 'true') {
            $this->fail('Node required in CI for layer-2 XSS test (see Resolved Q #17)');
        }

        $this->markTestSkipped('node not available locally — test is CI-mandatory but dev-optional');
    }

    // Minimal DOM shim — only the methods the hook body actually uses.
    $shim = <<<'JS_WRAP'
            const el = {
                _nodes: [],
                querySelectorAll(sel) { return this._nodes; },
            };
            const node = {
                _html: '',
                _text: '{"foo": "<script>alert(1)</script>"}',
                get textContent() { return this._text; },
                set textContent(v) { this._text = v; },
                replaceChildren() { this._html = ''; },
                insertAdjacentHTML(pos, html) {
                    if (pos === 'afterbegin') { this._html = html + this._html; }
                },
                // _qiColorizedSrc is an expando; plain JS property access works.
            };
            el._nodes = [node];

            // Fake Livewire just enough for registerQueueInsightsHook to call .hook().
            let hookCallback = null;
            globalThis.Livewire = {
                hook(event, cb) { if (event === 'morph.updated') hookCallback = cb; }
            };
    JS_WRAP;

    $runner = <<<'JS_WRAP'

            // Run the hook body against the shim.
            registerQueueInsightsHook();
            hookCallback({ el: el });

            // Emit final state for the PHP side to inspect.
            console.log(JSON.stringify({ html: node._html, text: node._text }));
    JS_WRAP;

    // Reassemble: shim first, then highlightJson source, then registerQueueInsightsHook, then runner.
    $highlightSrc = 'function highlightJson(src) {' . $highlightMatch[1] . "\n        }";
    $hookSrc = 'function registerQueueInsightsHook() {' . $hookMatch[1] . "\n        }";
    $program = $shim . "\n" . $highlightSrc . "\n" . $hookSrc . "\n" . $runner;

    // Write program to a temp file so we don't have to shell-escape the whole thing.
    $tmp = tempnam(sys_get_temp_dir(), 'qi-xss-') . '.js';
    file_put_contents($tmp, $program);

    $nodeProcess = new Process(['node', $tmp]);
    $nodeProcess->run();

    $output = $nodeProcess->getOutput() . $nodeProcess->getErrorOutput();
    @unlink($tmp);

    $decoded = json_decode(trim($output), true);
    expect($decoded)->toBeArray();

    $finalHtml = $decoded['html'] ?? '';
    expect($finalHtml)->toBeString()->not->toBeEmpty()
        ->toContain('&lt;script&gt;')
        ->and($finalHtml)->not->toContain('<script>alert(1)</script>')
        ->and($finalHtml)->not->toContain('<script>');
});
