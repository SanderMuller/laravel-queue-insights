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

it('renders scheduler families when scheduler is enabled and toggles are on', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_runs_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_hung_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_in_flight', true);

    R::conn()->command('rpush', [KeyPrefix::make('sched:tasks:order'), 'sync-customers']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:sync-customers'), 'total_runs', '12']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:sync-customers'), 'total_failed', '2']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:sync-customers'), 'total_hung', '1']);
    R::conn()->command('zadd', [KeyPrefix::make('sched:running-index'), 9999, 'sync-customers']);

    $body = $this->withHeader('Authorization', 'Bearer secret-token')
        ->get('/metrics')
        ->getContent();

    expect($body)
        ->toContain('# TYPE queue_insights_scheduled_task_runs_total counter')
        ->toContain('queue_insights_scheduled_task_runs_total{status="success",task="sync-customers"} 10')
        ->toContain('queue_insights_scheduled_task_runs_total{status="failed",task="sync-customers"} 2')
        ->toContain('# TYPE queue_insights_scheduled_task_hung_total counter')
        ->toContain('queue_insights_scheduled_task_hung_total{task="sync-customers"} 1')
        ->toContain('# TYPE queue_insights_scheduled_task_in_flight gauge')
        ->toContain('queue_insights_scheduled_task_in_flight{task="sync-customers"} 1');
});

it('emits no scheduler families when scheduler.enabled is false even with metric toggles on', function (): void {
    config()->set('queue-insights.scheduler.enabled', false);
    config()->set('queue-insights.prometheus.metrics.scheduler_runs_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_hung_total', true);
    config()->set('queue-insights.prometheus.metrics.scheduler_in_flight', true);

    R::conn()->command('rpush', [KeyPrefix::make('sched:tasks:order'), 'sync-customers']);
    R::conn()->command('hset', [KeyPrefix::make('sched:counters:sync-customers'), 'total_hung', '5']);

    $body = $this->withHeader('Authorization', 'Bearer secret-token')
        ->get('/metrics')
        ->getContent();

    expect($body)
        ->not->toContain('queue_insights_scheduled_task_')
        ->not->toContain('queue_insights_scheduled_snapshot_')
        ->not->toContain('queue_insights_scheduled_sweeper_');
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

it('labels a physically-named snapshot entry with its logical queue name', function (): void {
    // The snapshot driver writes metrics under the logical key on a suffixed
    // connection, so the collectors have to read (and label) the same one.
    config()->set('queue.connections.cloud', [
        'driver' => 'cloud',
        'connection' => ['driver' => 'sqs', 'suffix' => '-abc123'],
    ]);
    config()->set('queue-insights.snapshots', [
        ['connection' => 'cloud', 'queue' => 'work-abc123'],
    ]);

    R::conn()->command('setex', [KeyPrefix::make('live:depth:cloud:work'), 90, '4']);

    $body = $this->withHeader('Authorization', 'Bearer secret-token')
        ->get('/metrics')
        ->getContent();

    expect($body)->toContain('queue_insights_queue_depth{connection="cloud",queue="work"} 4');
});
