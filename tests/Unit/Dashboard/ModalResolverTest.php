<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Dashboard\ModalResolver;
use SanderMuller\QueueInsights\QueueInsights;

// `QueueInsights` is final; Mockery can't substitute it. The pure-scan
// resolver paths never call into the service, so a real instance is
// fine for unit coverage. The service-backed fallback paths in
// `selectedPending` (`findPendingByUuid`) and `selectedBatch`
// (`BatchReader::detailRow`) are covered by feature tests:
//   - tests/Feature/Http/PendingModalTest.php
//   - tests/Feature/Http/BatchesSectionTest.php
function modalResolver(): ModalResolver
{
    return new ModalResolver(new QueueInsights());
}

it('selectedPayload returns null when no id is selected', function (): void {
    expect(modalResolver()->selectedPayload(null, []))->toBeNull();
});

it('selectedPayload finds the matching row by _id', function (): void {
    $rows = [
        ['_id' => '01HK0M1', 'class' => 'A'],
        ['_id' => '01HK0M2', 'class' => 'B'],
    ];

    expect(modalResolver()->selectedPayload('01HK0M2', $rows))->toBe($rows[1]);
});

it('selectedPayload returns null when the id is not in the loaded window', function (): void {
    $rows = [['_id' => '01HK0M1', 'class' => 'A']];

    expect(modalResolver()->selectedPayload('aged-out', $rows))->toBeNull();
});

it('selectedFailed returns null when no id is selected', function (): void {
    expect(modalResolver()->selectedFailed(null, []))->toBeNull();
});

it('selectedFailed finds the matching row by numeric id', function (): void {
    $rows = [
        ['id' => 7, 'display_name' => 'A'],
        ['id' => 42, 'display_name' => 'B'],
    ];

    expect(modalResolver()->selectedFailed(42, $rows))->toBe($rows[1]);
});

it('selectedFailed coerces stringy numeric ids before comparison', function (): void {
    $rows = [['id' => '42', 'display_name' => 'A']];

    expect(modalResolver()->selectedFailed(42, $rows))->toBe($rows[0]);
});

it('selectedFailed returns null when the id is not in the loaded window', function (): void {
    expect(modalResolver()->selectedFailed(99, [['id' => 7]]))->toBeNull();
});

it('selectedPending returns null when no uuid is selected', function (): void {
    expect(modalResolver()->selectedPending(null, []))->toBeNull();
});

it('selectedPending hits the loaded rows without falling back to the service', function (): void {
    $rows = [
        ['uuid' => 'u-1', 'class' => 'A'],
        ['uuid' => 'u-2', 'class' => 'B'],
    ];

    expect(modalResolver()->selectedPending('u-2', $rows))->toBe($rows[1]);
});

it('selectedBatch returns null when no batch is expanded', function (): void {
    expect(modalResolver()->selectedBatch('', []))->toBeNull();
});

it('selectedBatch hits the loaded section rows without falling back', function (): void {
    $batches = [
        ['id' => 'b-1', 'name' => 'A'],
        ['id' => 'b-2', 'name' => 'B'],
    ];

    expect(modalResolver()->selectedBatch('b-2', $batches))->toBe($batches[1]);
});
