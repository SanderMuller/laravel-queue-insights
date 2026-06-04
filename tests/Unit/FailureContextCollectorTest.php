<?php declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use SanderMuller\QueueInsights\Support\FailureContextCollector;

beforeEach(function (): void {
    Context::flush();
    config()->set('queue-insights.capture.redact_keys', ['.*password.*', '.*secret.*', '.*token.*', '.*api[_-]?key.*', '.*authorization.*', '.*credential.*']);
    config()->set('queue-insights.failure_context.enabled', true);
    config()->set('queue-insights.failure_context.capture_app_context', true);
    config()->set('queue-insights.failure_context.context_keys', []);
    config()->set('queue-insights.failure_context.capture_environment', false);
    config()->set('queue-insights.failure_context.max_value_bytes', 2048);
    config()->set('queue-insights.failure_context.release_resolver');
});

afterEach(fn () => Context::flush());

it('captures visible context with sensitive keys redacted, including common variants', function (): void {
    Context::add([
        'user_id' => 42,
        'tenant' => 'acme',
        'password' => 'hunter2',
        'api_key' => 'sk-live-x',
        'access_token' => 'tok-1',
        'db_password' => 'pw-2',
        'client_secret' => 'cs-3',
    ]);

    $result = (new FailureContextCollector())->collect();

    expect($result['app_context']['user_id'])->toBe(42)
        ->and($result['app_context']['tenant'])->toBe('acme')
        ->and($result['app_context']['password'])->toBe('[REDACTED]')
        ->and($result['app_context']['api_key'])->toBe('[REDACTED]')
        ->and($result['app_context']['access_token'])->toBe('[REDACTED]')
        ->and($result['app_context']['db_password'])->toBe('[REDACTED]')
        ->and($result['app_context']['client_secret'])->toBe('[REDACTED]');
});

it('normalizes object context values so nested secrets are redacted', function (): void {
    $payload = new class implements JsonSerializable {
        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return ['order_id' => 99, 'access_token' => 'leak-me'];
        }
    };
    Context::add(['payload' => $payload]);

    $context = (new FailureContextCollector())->collect()['app_context'];

    expect($context['payload'])->toBeArray()
        ->and($context['payload']['order_id'])->toBe(99)
        ->and($context['payload']['access_token'])->toBe('[REDACTED]');
});

it('restricts to the allowlist when context_keys is set', function (): void {
    config()->set('queue-insights.failure_context.context_keys', ['user_id']);
    Context::add(['user_id' => 42, 'tenant' => 'acme']);

    $result = (new FailureContextCollector())->collect();

    expect($result['app_context'])->toBe(['user_id' => 42]);
});

it('truncates over-long context values to max_value_bytes', function (): void {
    config()->set('queue-insights.failure_context.max_value_bytes', 10);
    Context::add(['blob' => str_repeat('x', 50)]);

    $value = (new FailureContextCollector())->collect()['app_context']['blob'];

    expect($value)->toBeString()
        ->and($value)->toEndWith('…[truncated]')
        ->and($value)->toStartWith('xxxxxxxxxx');
});

it('captures nothing for app_context when capture_app_context is false', function (): void {
    config()->set('queue-insights.failure_context.capture_app_context', false);
    Context::add(['user_id' => 42]);

    expect((new FailureContextCollector())->collect()['app_context'])
        ->toBeEmpty();
});

it('captures the environment snapshot when enabled', function (): void {
    config()->set('queue-insights.failure_context.capture_environment', true);

    $env = (new FailureContextCollector())->collect()['environment'];

    expect($env['host'])->toBeString()
        ->and($env['pid'])->toBeInt()
        ->and($env['env'])->toBe('testing');
});

it('resolves the release via a callable resolver', function (): void {
    config()->set('queue-insights.failure_context.capture_environment', true);
    config()->set('queue-insights.failure_context.release_resolver', fn (): string => 'deploy-abc123');

    expect((new FailureContextCollector())->collect()['environment']['release'])->toBe('deploy-abc123');
});

it('resolves the release from a config-key string resolver', function (): void {
    config()->set('queue-insights.failure_context.capture_environment', true);
    config()->set('app.version', '2.0.0');
    config()->set('queue-insights.failure_context.release_resolver', 'app.version');

    expect((new FailureContextCollector())->collect()['environment']['release'])->toBe('2.0.0');
});

it('returns both sections empty when the feature is disabled', function (): void {
    config()->set('queue-insights.failure_context.enabled', false);
    config()->set('queue-insights.failure_context.capture_environment', true);
    Context::add(['user_id' => 42]);

    $result = (new FailureContextCollector())->collect();

    expect($result['app_context'])
        ->toBeEmpty()
        ->and($result['environment'])
        ->toBeEmpty();
});

it('returns empty app_context when no context is set', function (): void {
    expect((new FailureContextCollector())->collect()['app_context'])
        ->toBeEmpty();
});
