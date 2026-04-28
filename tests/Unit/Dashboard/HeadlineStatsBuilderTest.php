<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Dashboard\HeadlineStatsBuilder;

it('build derives jobs/min and past-hour totals from the latest throughput bucket', function (): void {
    $throughput = [
        ['timestamp' => 1, 'processed' => 100, 'failed' => 5],
        ['timestamp' => 2, 'processed' => 240, 'failed' => 12],
        ['timestamp' => 3, 'processed' => 360, 'failed' => 7],
    ];

    $stats = (new HeadlineStatsBuilder())->build($throughput, [], []);

    expect($stats['jobs_per_minute'])->toBe(6) // round(360 / 60)
        ->and($stats['jobs_past_hour'])->toBe(360)
        ->and($stats['failed_past_hour'])->toBe(7)
        ->and($stats['max_throughput_hour'])->toBe(360);
});

it('build returns zeros across the throughput stats when the series is empty', function (): void {
    $stats = (new HeadlineStatsBuilder())->build([], [], []);

    expect($stats['jobs_per_minute'])->toBe(0)
        ->and($stats['jobs_past_hour'])->toBe(0)
        ->and($stats['failed_past_hour'])->toBe(0)
        ->and($stats['max_throughput_hour'])->toBe(0);
});

it('build picks max wait_p95_ms across queues and ignores non-numeric entries', function (): void {
    $queues = [
        ['queue' => 'a', 'wait_p95_ms' => 120],
        ['queue' => 'b', 'wait_p95_ms' => 540],
        ['queue' => 'c', 'wait_p95_ms' => null],
        ['queue' => 'd', 'wait_p95_ms' => 'oops'],
        ['queue' => 'e'],
    ];

    $stats = (new HeadlineStatsBuilder())->build([], $queues, []);

    expect($stats['max_wait_ms'])->toBe(540);
});

it('build returns null max_wait_ms when no queue carries a numeric percentile', function (): void {
    $queues = [
        ['queue' => 'a', 'wait_p95_ms' => null],
        ['queue' => 'b'],
    ];

    $stats = (new HeadlineStatsBuilder())->build([], $queues, []);

    expect($stats['max_wait_ms'])->toBeNull();
});

it('build picks max p95_ms across class rows for max_runtime_ms', function (): void {
    $classes = [
        ['class' => 'A', 'p95_ms' => 1200],
        ['class' => 'B', 'p95_ms' => 8400],
        ['class' => 'C', 'p95_ms' => null],
    ];

    $stats = (new HeadlineStatsBuilder())->build([], [], $classes);

    expect($stats['max_runtime_ms'])->toBe(8400);
});
