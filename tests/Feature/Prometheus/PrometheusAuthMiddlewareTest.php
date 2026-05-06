<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);
});

it('refuses with 403 when neither token nor allow_ips is configured', function (): void {
    config()->set('queue-insights.prometheus.token');
    config()->set('queue-insights.prometheus.allow_ips', []);

    $response = $this->get('/metrics');

    $response->assertForbidden();

    expect($response->getContent())->toContain('Configure');
});

it('accepts a valid bearer token and rejects an invalid one', function (): void {
    config()->set('queue-insights.prometheus.token', 'secret-token');

    $this->get('/metrics')->assertForbidden();

    $this->withHeader('Authorization', 'Bearer wrong')
        ->get('/metrics')
        ->assertForbidden();

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->get('/metrics')
        ->assertOk();
});

it('uses constant-time comparison for the token check', function (): void {
    // Surface check — hash_equals is what guards `Authorization` parsing;
    // the test asserts the well-formed-but-wrong token is still 403 (i.e.
    // we don't short-circuit on prefix match).
    config()->set('queue-insights.prometheus.token', 'aaaaaaaa');
    $this->withHeader('Authorization', 'Bearer aaaaaaab')
        ->get('/metrics')
        ->assertForbidden();
});

it('allows ip in CIDR allow-list when no token configured', function (): void {
    config()->set('queue-insights.prometheus.token');
    config()->set('queue-insights.prometheus.allow_ips', ['127.0.0.0/24']);

    $response = $this->get('/metrics');

    $response->assertOk();
});

it('honors an explicit empty middleware override (host opts out of package gate — codex review)', function (): void {
    // Operator exposing /metrics behind outer infra auth (Kong, an
    // ingress controller, basic-auth at the reverse proxy, etc.) sets
    // `prometheus.middleware = []` to disable the package-shipped 403
    // gate. Re-include the route file with the override in place so we
    // exercise the actual fallback logic, not a hand-rolled stand-in.
    config()->set('queue-insights.prometheus.middleware', []);
    config()->set('queue-insights.prometheus.path', 'metrics-empty-override');

    require __DIR__ . '/../../../routes/prometheus.php';

    // No Authorization header — would 403 under the default gate.
    $this->get('/metrics-empty-override')->assertOk();
});

it('rejects ip outside allow-list', function (): void {
    config()->set('queue-insights.prometheus.token');
    config()->set('queue-insights.prometheus.allow_ips', ['10.0.0.0/8']);

    $response = $this->get('/metrics');

    $response->assertForbidden();
});
