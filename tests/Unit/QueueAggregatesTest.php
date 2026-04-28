<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\QueueAggregates;

it('aggregate sums depth + inflight and partitions by error/stale flags', function (): void {
    $queues = [
        ['queue' => 'a', 'connection' => 'redis', 'depth' => 10, 'inflight' => 2, 'error' => false, 'stale' => false],
        ['queue' => 'b', 'connection' => 'redis', 'depth' => 50, 'inflight' => 4, 'error' => true, 'stale' => false],
        ['queue' => 'c', 'connection' => 'sqs', 'depth' => 7, 'inflight' => 0, 'error' => false, 'stale' => true],
        ['queue' => 'd', 'connection' => 'redis', 'depth' => 0, 'inflight' => 1, 'error' => false, 'stale' => false],
    ];

    $result = QueueAggregates::aggregate($queues);

    expect($result['total_depth'])->toBe(67)
        ->and($result['total_inflight'])->toBe(7)
        ->and(array_column($result['at_risk'], 'queue'))->toBe(['b', 'c'])
        ->and(array_column($result['healthy'], 'queue'))->toBe(['a', 'd'])
        ->and(array_column($result['deepest'], 'queue'))->toBe(['b', 'a', 'c', 'd']);
});

it('aggregate treats missing/non-numeric depth + inflight as zero', function (): void {
    $queues = [
        ['queue' => 'a', 'connection' => 'redis', 'depth' => null, 'error' => false, 'stale' => false],
        ['queue' => 'b', 'connection' => 'redis', 'depth' => 'oops', 'inflight' => 'oops', 'error' => false, 'stale' => false],
    ];

    $result = QueueAggregates::aggregate($queues);

    expect($result['total_depth'])->toBe(0)
        ->and($result['total_inflight'])->toBe(0);
});

it('queuePreview pads at-risk with deepest when under cap, dedupes by (connection, queue)', function (): void {
    $atRisk = [
        ['queue' => 'a', 'connection' => 'redis', 'depth' => 50, 'error' => true, 'stale' => false],
    ];
    $deepest = [
        ['queue' => 'b', 'connection' => 'redis', 'depth' => 100, 'error' => false, 'stale' => false],
        ['queue' => 'a', 'connection' => 'redis', 'depth' => 50, 'error' => true, 'stale' => false],
        ['queue' => 'c', 'connection' => 'sqs', 'depth' => 30, 'error' => false, 'stale' => false],
    ];

    $preview = QueueAggregates::queuePreview($atRisk, $deepest, 5);

    expect(array_column($preview, 'queue'))->toBe(['a', 'b', 'c']);
});

it('queuePreview honours the cap', function (): void {
    $atRisk = [
        ['queue' => 'a', 'connection' => 'redis', 'depth' => 1, 'error' => true, 'stale' => false],
        ['queue' => 'b', 'connection' => 'redis', 'depth' => 2, 'error' => true, 'stale' => false],
    ];
    $deepest = [
        ['queue' => 'c', 'connection' => 'redis', 'depth' => 3, 'error' => false, 'stale' => false],
        ['queue' => 'd', 'connection' => 'redis', 'depth' => 4, 'error' => false, 'stale' => false],
    ];

    $preview = QueueAggregates::queuePreview($atRisk, $deepest, 3);

    expect($preview)->toHaveCount(3)
        ->and(array_column($preview, 'queue'))->toBe(['a', 'b', 'c']);
});

it('pendingPreview tags in-flight rows and preserves in-flight → pending → delayed order', function (): void {
    $inFlight = [['uuid' => 'u1', 'class' => 'A']];
    $pending = [['uuid' => 'u2', 'class' => 'B']];
    $delayed = [['uuid' => 'u3', 'class' => 'C']];

    $preview = QueueAggregates::pendingPreview($inFlight, $pending, $delayed, 5);

    expect($preview)->toHaveCount(3)
        ->and($preview[0]['uuid'])->toBe('u1')
        ->and($preview[0]['_isInFlight'])->toBeTrue()
        ->and($preview[1]['uuid'])->toBe('u2')
        ->and($preview[1])->not->toHaveKey('_isInFlight')
        ->and($preview[2]['uuid'])->toBe('u3')
        ->and($preview[2])->not->toHaveKey('_isInFlight');
});

it('pendingPreview honours the cap across all three groups', function (): void {
    $inFlight = [['uuid' => 'u1'], ['uuid' => 'u2']];
    $pending = [['uuid' => 'u3'], ['uuid' => 'u4']];
    $delayed = [['uuid' => 'u5'], ['uuid' => 'u6']];

    $preview = QueueAggregates::pendingPreview($inFlight, $pending, $delayed, 4);

    expect(array_column($preview, 'uuid'))->toBe(['u1', 'u2', 'u3', 'u4']);
});
