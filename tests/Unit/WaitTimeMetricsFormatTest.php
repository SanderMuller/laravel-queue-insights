<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\WaitTimeMetrics;

it('format renders null as the em-dash placeholder', function (): void {
    expect(WaitTimeMetrics::format(null))->toBe('—');
});

it('format renders sub-second values as ms', function (): void {
    expect(WaitTimeMetrics::format(0))->toBe('0ms')
        ->and(WaitTimeMetrics::format(1))->toBe('1ms')
        ->and(WaitTimeMetrics::format(999))->toBe('999ms');
});

it('format renders sub-minute values as seconds with one decimal', function (): void {
    expect(WaitTimeMetrics::format(1000))->toBe('1.0s')
        ->and(WaitTimeMetrics::format(1499))->toBe('1.5s')
        ->and(WaitTimeMetrics::format(59_999))->toBe('60.0s');
});

it('format renders minute-or-greater values as minutes with one decimal', function (): void {
    expect(WaitTimeMetrics::format(60_000))->toBe('1.0m')
        ->and(WaitTimeMetrics::format(90_000))->toBe('1.5m')
        ->and(WaitTimeMetrics::format(3_600_000))->toBe('60.0m');
});
