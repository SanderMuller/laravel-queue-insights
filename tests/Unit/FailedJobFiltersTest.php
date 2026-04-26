<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\FailedJobFilters;

it('isEmpty is true when every field is the empty string', function (): void {
    expect((new FailedJobFilters())->isEmpty())->toBeTrue();
});

it('isEmpty is false when any field is non-empty', function (): void {
    expect((new FailedJobFilters(connection: 'redis'))->isEmpty())->toBeFalse()
        ->and((new FailedJobFilters(queue: 'video'))->isEmpty())->toBeFalse()
        ->and((new FailedJobFilters(class: 'App\\Foo'))->isEmpty())->toBeFalse()
        ->and((new FailedJobFilters(from: '2026-04-01'))->isEmpty())->toBeFalse()
        ->and((new FailedJobFilters(to: '2026-04-30'))->isEmpty())->toBeFalse();
});
