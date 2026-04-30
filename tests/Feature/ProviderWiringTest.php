<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
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

it('binds MetadataOnlySanitizer by default (capture = off)', function (): void {
    config()->set('queue-insights.capture.payloads', 'off');
    app()->forgetInstance(PayloadSanitizer::class);

    // PHPStan infers the container binding's default match arm and reports the
    // assertion as redundant/impossible — but `forgetInstance` + config flip is
    // exactly what re-runs the binding closure with the new branch at runtime.
    // @phpstan-ignore pest.impossibleExpectation
    expect(resolve(PayloadSanitizer::class))->toBeInstanceOf(MetadataOnlySanitizer::class);
});

it('binds MetadataOnlySanitizer when capture.payloads = metadata', function (): void {
    config()->set('queue-insights.capture.payloads', 'metadata');
    app()->forgetInstance(PayloadSanitizer::class);

    // @phpstan-ignore pest.impossibleExpectation
    expect(resolve(PayloadSanitizer::class))->toBeInstanceOf(MetadataOnlySanitizer::class);
});

it('binds KeyRedactingSanitizer when capture.payloads = full', function (): void {
    config()->set('queue-insights.capture.payloads', 'full');
    app()->forgetInstance(PayloadSanitizer::class);

    // @phpstan-ignore pest.redundantExpectation
    expect(resolve(PayloadSanitizer::class))->toBeInstanceOf(KeyRedactingSanitizer::class);
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
