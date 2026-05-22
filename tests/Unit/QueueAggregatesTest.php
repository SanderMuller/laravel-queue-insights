<?php declare(strict_types=1);

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
        ->and(array_column($result['healthy'], 'queue'))->toBe(['a', 'd']);
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
