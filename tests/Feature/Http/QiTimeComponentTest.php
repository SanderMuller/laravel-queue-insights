<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;

it('renders <time> with UTC datetime attribute and data-qi-time hook', function (): void {
    $at = Date::parse('2026-04-29T13:17:56+00:00');

    $html = Blade::render('<x-queue-insights::qi-time :at="$at"/>', ['at' => $at]);

    expect($html)
        ->toContain('<time')
        ->toContain('datetime="2026-04-29T13:17:56+00:00"')
        ->toContain('data-qi-time')
        ->toContain('data-qi-time-format="relative"');
});

it('absolute format renders human-friendly text and mono variant adds font-mono', function (): void {
    $at = Date::parse('2026-04-29T13:17:56+00:00');

    $html = Blade::render('<x-queue-insights::qi-time :at="$at" format="absolute-mono"/>', ['at' => $at]);

    expect($html)
        ->toContain('data-qi-time-format="absolute-mono"')
        ->toContain('font-mono')
        ->not->toContain('2026-04-29T13:17:56+00:00>'); // raw ISO not visible in body
});

it('emits prefix as data attribute and prepends to display text', function (): void {
    $at = Date::parse('2026-04-29T13:17:56+00:00');

    $html = Blade::render('<x-queue-insights::qi-time :at="$at" prefix="started"/>', ['at' => $at]);

    expect($html)
        ->toContain('data-qi-time-prefix="started"')
        ->toMatch('/started\s+\S/'); // server fallback display has the prefix
});

it('renders fallback span when value is null', function (): void {
    $html = Blade::render('<x-queue-insights::qi-time :at="$at"/>', ['at' => null]);

    expect($html)->toContain('—')->not->toContain('<time');
});

it('accepts unix timestamp ints', function (): void {
    $ts = Date::parse('2026-04-29T13:17:56+00:00')->getTimestamp();

    $html = Blade::render('<x-queue-insights::qi-time :at="$ts"/>', ['ts' => $ts]);

    expect($html)
        ->toContain('<time')
        ->toContain('datetime="2026-04-29T13:17:56+00:00"');
});
