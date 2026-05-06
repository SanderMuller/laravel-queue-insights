<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\Renderer;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.prometheus.token', 'secret-token');
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'work'],
    ]);
});

it('returns 200 with text/plain content-type and renders queue_depth', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('live:depth:sqs:work'), 90, '7']);

    $response = $this->withHeader('Authorization', 'Bearer secret-token')
        ->get('/metrics');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(Renderer::CONTENT_TYPE_TEXT);
    $body = $response->getContent();
    expect($body)->toContain('# TYPE queue_insights_queue_depth gauge')
        ->toContain('queue_insights_queue_depth{connection="sqs",queue="work"} 7')->not->toEndWith("# EOF\n");
});

it('negotiates openmetrics flavour from Accept header and appends # EOF', function (): void {
    R::conn()->command('setex', [KeyPrefix::make('live:depth:sqs:work'), 90, '3']);

    $response = $this->withHeader('Authorization', 'Bearer secret-token')
        ->withHeader('Accept', 'application/openmetrics-text; version=1.0.0')
        ->get('/metrics');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(Renderer::CONTENT_TYPE_OPENMETRICS)
        ->and($response->getContent())
        ->toEndWith("# EOF\n");
});
