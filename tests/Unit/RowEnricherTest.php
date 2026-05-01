<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\RowEnricher;

it('decodeChain returns null for an empty string', function (): void {
    expect(RowEnricher::decodeChain(''))->toBeNull();
});

it('decodeChain returns null for malformed JSON', function (): void {
    expect(RowEnricher::decodeChain('not-json'))->toBeNull();
});

it('decodeChain returns null when the decoded value is not a non-empty array', function (): void {
    expect(RowEnricher::decodeChain('[]'))->toBeNull()
        ->and(RowEnricher::decodeChain('null'))->toBeNull()
        ->and(RowEnricher::decodeChain('"string"'))->toBeNull();
});

it('decodeChain skips entries missing a class field but keeps the rest', function (): void {
    $encoded = json_encode([
        ['class' => 'App\\Jobs\\Alpha', 'connection' => 'redis', 'queue' => 'work'],
        ['connection' => 'redis'],   // missing class — dropped
        ['class' => '', 'queue' => 'q'], // empty class — dropped
        ['class' => 'App\\Jobs\\Beta', 'connection' => null, 'queue' => null],
    ]);

    $result = RowEnricher::decodeChain($encoded === false ? '' : $encoded);

    expect($result)->not->toBeNull();
    if ($result === null) {
        return;
    }

    expect($result['next_class'])->toBe('App\\Jobs\\Alpha')
        ->and($result['remaining'])->toBe(2)
        ->and($result['chain_connection'])->toBe('redis')
        ->and($result['chain_queue'])->toBe('work')
        ->and(array_column($result['jobs'], 'class'))->toBe(['App\\Jobs\\Alpha', 'App\\Jobs\\Beta']);
});

it('decodeChain returns null when every entry is invalid', function (): void {
    $encoded = json_encode([
        ['connection' => 'redis'],
        'not-an-array',
        ['class' => ''],
    ]);

    expect(RowEnricher::decodeChain($encoded === false ? '' : $encoded))
        ->toBeNull();
});

it('decodeChain coerces empty connection / queue strings to null', function (): void {
    $encoded = json_encode([
        ['class' => 'App\\Jobs\\Solo', 'connection' => '', 'queue' => ''],
    ]);

    $result = RowEnricher::decodeChain($encoded === false ? '' : $encoded);

    expect($result)->not->toBeNull();
    if ($result === null) {
        return;
    }

    expect($result['chain_connection'])->toBeNull()
        ->and($result['chain_queue'])->toBeNull();
});

it('failed handles rows whose payload is missing or unparseable', function (): void {
    $rows = [
        ['id' => 1, 'uuid' => 'u-1', 'connection' => 'redis', 'queue' => 'q', 'failed_at' => '2026-04-26 10:00:00', 'payload' => null, 'exception' => 'X: y'],
        ['id' => 2, 'uuid' => 'u-2', 'connection' => 'redis', 'queue' => 'q', 'failed_at' => '2026-04-26 10:00:00', 'payload' => '{not json', 'exception' => 'X: y'],
    ];

    $enriched = RowEnricher::failed($rows);

    // Bad payloads must not throw — display fields drop to null but the row
    // still renders so the operator can see the failure.
    expect($enriched)->toHaveCount(2)
        ->and($enriched[0]['display_name'])->toBeNull()
        ->and($enriched[0]['attempts'])->toBeNull()
        ->and($enriched[0]['max_tries'])->toBeNull()
        ->and($enriched[0]['batch_id'])->toBeNull()
        ->and($enriched[1]['display_name'])->toBeNull();
});

it('failed splits the exception header on the first colon', function (): void {
    $rows = [
        ['id' => 1, 'uuid' => 'u-1', 'connection' => 'redis', 'queue' => 'q', 'failed_at' => 'x', 'payload' => null, 'exception' => "RuntimeException: boom: with: colons\n#0 trace"],
    ];

    $enriched = RowEnricher::failed($rows);

    expect($enriched[0]['exception_class'])->toBe('RuntimeException')
        ->and($enriched[0]['exception_message'])->toBe('boom: with: colons');
});

it('failed leaves exception_message empty when there is no colon', function (): void {
    $rows = [
        ['id' => 1, 'uuid' => '', 'connection' => 'redis', 'queue' => 'q', 'failed_at' => 'x', 'payload' => null, 'exception' => 'JustAClass'],
    ];

    $enriched = RowEnricher::failed($rows);

    expect($enriched[0]['exception_class'])->toBe('JustAClass')
        ->and($enriched[0]['exception_message'])
        ->toBeEmpty()
        ->and($enriched[0]['short_uuid'])
        ->toBeEmpty();
});

it('chainFromPayload returns null when payload data.command is absent or non-string', function (): void {
    expect(RowEnricher::chainFromPayload(null))->toBeNull()
        ->and(RowEnricher::chainFromPayload(['data' => null]))->toBeNull()
        ->and(RowEnricher::chainFromPayload(['data' => ['command' => null]]))->toBeNull()
        ->and(RowEnricher::chainFromPayload(['data' => ['command' => '']]))->toBeNull();
});
