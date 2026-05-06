<?php declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
    config()->set('queue-insights.prometheus.pushgateway.url', 'http://gateway.example/metrics');
    config()->set('queue-insights.prometheus.pushgateway.job', 'qi-test');
});

it('PUTs the rendered exposition body with grouping job + instance', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.instance', 'worker-01');

    $exit = Artisan::call('queue-insights:prometheus-push');

    expect($exit)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PUT') {
            return false;
        }

        if ($request->url() !== 'http://gateway.example/metrics/job/qi-test/instance/worker-01') {
            return false;
        }

        return str_contains((string) $request->body(), '# TYPE queue_insights_queue_depth gauge');
    });
});

it('omits the instance segment when instance is unset and --accept-shared-grouping is passed', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.instance');

    $exit = Artisan::call('queue-insights:prometheus-push', ['--accept-shared-grouping' => true]);

    expect($exit)->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gateway.example/metrics/job/qi-test'
        && $request->method() === 'PUT');
});

it('refuses to push when instance is unset without --accept-shared-grouping (clustered overwrite guard)', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.instance');

    $exit = Artisan::call('queue-insights:prometheus-push');

    expect($exit)->toBe(2);
    Http::assertNothingSent();
});

it('refuses to push when pushgateway.url is unset', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.url', '');

    $exit = Artisan::call('queue-insights:prometheus-push', ['--accept-shared-grouping' => true]);

    expect($exit)->toBe(2);
    Http::assertNothingSent();
});

it('--delete sends DELETE to the grouping URL and pushes no body', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.instance', 'worker-01');

    $exit = Artisan::call('queue-insights:prometheus-push', ['--delete' => true]);

    expect($exit)->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://gateway.example/metrics/job/qi-test/instance/worker-01');
});

it('returns INVALID (2) for malformed pushgateway URL — distinct from HTTP failure exit (1)', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.url', 'not-a-url');
    config()->set('queue-insights.prometheus.pushgateway.instance', 'worker-01');

    $exit = Artisan::call('queue-insights:prometheus-push');

    expect($exit)->toBe(2);
    Http::assertNothingSent();
});

it('returns non-zero exit code when Pushgateway responds 5xx', function (): void {
    Http::fake([
        'gateway.example/*' => Http::response('boom', 503),
    ]);
    config()->set('queue-insights.prometheus.pushgateway.instance', 'worker-01');

    $exit = Artisan::call('queue-insights:prometheus-push');

    expect($exit)->toBe(1);
});

it('forwards basic auth credentials embedded in the URL', function (): void {
    Http::fake();
    config()->set('queue-insights.prometheus.pushgateway.url', 'http://user:secret@gateway.example/metrics');
    config()->set('queue-insights.prometheus.pushgateway.instance', 'worker-01');

    Artisan::call('queue-insights:prometheus-push');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Basic ' . base64_encode('user:secret')));
});
