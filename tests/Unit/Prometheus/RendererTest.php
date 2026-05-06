<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;
use SanderMuller\QueueInsights\Prometheus\Renderer;

it('renders HELP and TYPE banners followed by samples', function (): void {
    $family = new MetricFamily(
        name: 'queue_insights_queue_depth',
        type: 'gauge',
        help: 'Current depth.',
        samples: [
            new Sample('queue_insights_queue_depth', ['connection' => 'sqs', 'queue' => 'work'], 42.0),
        ],
    );

    $body = (new Renderer())->render([$family]);

    expect($body)->toContain("# HELP queue_insights_queue_depth Current depth.\n")
        ->toContain("# TYPE queue_insights_queue_depth gauge\n")
        ->toContain('queue_insights_queue_depth{connection="sqs",queue="work"} 42');
});

it('sorts labels alphabetically for deterministic output', function (): void {
    $family = new MetricFamily(
        name: 'queue_insights_q',
        type: 'gauge',
        help: 'h',
        samples: [
            new Sample('queue_insights_q', ['z' => '1', 'a' => '2', 'm' => '3'], 1.0),
        ],
    );

    $body = (new Renderer())->render([$family]);

    expect($body)->toContain('queue_insights_q{a="2",m="3",z="1"} 1');
});

it('escapes label values per the text-format spec', function (): void {
    $family = new MetricFamily(
        name: 'qi_test',
        type: 'gauge',
        help: 'help',
        samples: [
            new Sample('qi_test', ['class' => 'App\\Jobs\\Foo', 'note' => "line\nbreak"], 1.0),
        ],
    );

    $body = (new Renderer())->render([$family]);

    expect($body)->toContain('class="App\\\\Jobs\\\\Foo"')
        ->toContain('note="line\nbreak"');
});

it('appends # EOF only for openmetrics flavour', function (): void {
    $family = new MetricFamily('qi_test', 'gauge', 'h', []);
    $renderer = new Renderer();

    expect($renderer->render([$family], openmetrics: false))->not->toContain('# EOF')
        ->and($renderer->render([$family], openmetrics: true))
        ->toEndWith("# EOF\n");
});

it('formats whole-number floats as integers and trims trailing zeros otherwise', function (): void {
    $family = new MetricFamily('qi_test', 'gauge', 'h', [
        new Sample('qi_test', ['k' => 'int'], 42.0),
        new Sample('qi_test', ['k' => 'frac'], 1.5),
        new Sample('qi_test', ['k' => 'inf'], INF),
        new Sample('qi_test', ['k' => 'nan'], NAN),
    ]);

    $body = (new Renderer())->render([$family]);

    expect($body)->toContain('qi_test{k="int"} 42' . "\n")
        ->toContain('qi_test{k="frac"} 1.5' . "\n")
        ->toContain('qi_test{k="inf"} +Inf')
        ->toContain('qi_test{k="nan"} NaN');
});

it('rejects invalid metric names', function (): void {
    $family = new MetricFamily('1bad', 'gauge', 'h', []);

    expect(fn (): string => (new Renderer())->render([$family]))
        ->toThrow(InvalidArgumentException::class);
});

it('emits the correct content-type constants', function (): void {
    expect(Renderer::CONTENT_TYPE_TEXT)->toBe('text/plain; version=0.0.4; charset=utf-8')
        ->and(Renderer::CONTENT_TYPE_OPENMETRICS)
        ->toBe('application/openmetrics-text; version=1.0.0; charset=utf-8');
});
