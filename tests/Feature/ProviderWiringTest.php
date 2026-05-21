<?php declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Http\Middleware\SetInitiatorOrigin;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Support\Sanitizers\KeyRedactingSanitizer;
use SanderMuller\QueueInsights\Support\Sanitizers\MetadataOnlySanitizer;

it('registers the three job listeners by default', function (): void {
    expect(Event::hasListeners(JobProcessing::class))->toBeTrue()
        ->and(Event::hasListeners(JobProcessed::class))->toBeTrue()
        ->and(Event::hasListeners(JobFailed::class))->toBeTrue();
});

it('does not register listeners when queue-insights.enabled = false', function (): void {
    config()->set('queue-insights.enabled', false);
    Event::forget(JobProcessing::class);
    Event::forget(JobProcessed::class);
    Event::forget(JobFailed::class);

    (new QueueInsightsServiceProvider(app()))->boot();

    expect(Event::hasListeners(JobProcessing::class))->toBeFalse()
        ->and(Event::hasListeners(JobProcessed::class))->toBeFalse()
        ->and(Event::hasListeners(JobFailed::class))->toBeFalse();
});

// PHPStan's laravel-plugin narrows `resolve(PayloadSanitizer::class)` to the
// container's static-default binding (KeyRedactingSanitizer when the default
// match arm fires), but `forgetInstance` + a config flip re-runs the binding
// closure with a different branch at runtime. Comparing the resolved class
// FQN as a string sidesteps the narrowing so phpstan doesn't flag the
// conditional-binding tests as impossible/redundant.
it('binds MetadataOnlySanitizer by default (capture = off)', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');
    app()->forgetInstance(PayloadSanitizer::class);

    expect(resolve(PayloadSanitizer::class)::class)->toBe(MetadataOnlySanitizer::class);
});

it('binds MetadataOnlySanitizer when capture.payloads = metadata', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');
    app()->forgetInstance(PayloadSanitizer::class);

    expect(resolve(PayloadSanitizer::class)::class)->toBe(MetadataOnlySanitizer::class);
});

it('binds KeyRedactingSanitizer when capture.payloads = full', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');
    app()->forgetInstance(PayloadSanitizer::class);

    expect(resolve(PayloadSanitizer::class)::class)->toBe(KeyRedactingSanitizer::class);
});

it('appends the SetInitiatorOrigin middleware to the web and api groups', function (): void {
    // registerInitiatorMiddleware() runs in boot() when initiator.enabled —
    // the behavioural origin tests exercise the middleware itself, this pins
    // the provider-side group registration that puts it in the pipeline.
    $groups = app(Router::class)->getMiddlewareGroups();

    expect($groups['web'] ?? [])->toContain(SetInitiatorOrigin::class)
        ->and($groups['api'] ?? [])->toContain(SetInitiatorOrigin::class);
});

it('honors a custom PayloadSanitizer binding from the host application', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');

    $custom = new class implements PayloadSanitizer {
        /**
         * @return array<string, string>
         */
        public function sanitize(JobProcessed $event): array
        {
            return ['custom' => 'marker'];
        }
    };

    app()->instance(PayloadSanitizer::class, $custom);

    expect(resolve(PayloadSanitizer::class))->toBe($custom);
});
