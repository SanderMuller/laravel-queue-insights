<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Dashboard\FilterOptionsBuilder;

beforeEach(function (): void {
    config()->set('queue-insights.snapshots', []);
});

it('build pulls connection + queue from configured snapshots and class from the roster', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
        ['connection' => 'sqs', 'queue' => 'reports'],
    ]);

    $classes = [
        ['class' => 'App\\Jobs\\Alpha'],
        ['class' => 'App\\Jobs\\Beta'],
    ];

    $options = (new FilterOptionsBuilder())->build($classes);

    expect($options['connections'])->toBe(['redis', 'sqs'])
        ->and($options['queues'])->toBe(['default', 'high', 'reports'])
        ->and($options['classes'])->toBe(['App\\Jobs\\Alpha', 'App\\Jobs\\Beta']);
});

it('build deduplicates and sorts the option lists', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'b'],
        ['connection' => 'redis', 'queue' => 'a'],
        ['connection' => 'sqs', 'queue' => 'b'],
    ]);

    $options = (new FilterOptionsBuilder())->build([]);

    expect($options['queues'])->toBe(['a', 'b'])
        ->and($options['connections'])->toBe(['redis', 'sqs']);
});

it('build skips empty + non-string values', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => '', 'queue' => null],
        ['connection' => 42, 'queue' => 'high'],
    ]);

    $options = (new FilterOptionsBuilder())->build([
        ['class' => 'App\\Jobs\\Alpha'],
        ['class' => ''],
        ['class' => null],
    ]);

    expect($options['connections'])->toBe(['redis'])
        ->and($options['queues'])->toBe(['default', 'high'])
        ->and($options['classes'])->toBe(['App\\Jobs\\Alpha']);
});

it('build tolerates non-array snapshot entries', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        'malformed-string-entry',
        null,
    ]);

    $options = (new FilterOptionsBuilder())->build([]);

    expect($options['connections'])->toBe(['redis'])
        ->and($options['queues'])->toBe(['default']);
});
