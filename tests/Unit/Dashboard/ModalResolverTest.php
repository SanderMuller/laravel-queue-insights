<?php

declare(strict_types=1);

use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use SanderMuller\QueueInsights\Dashboard\ModalResolver;
use SanderMuller\QueueInsights\QueueInsights;

// `QueueInsights` is `final readonly` in production. `dg/bypass-finals`
// strips `final` at autoload time inside the test process so Mockery
// can substitute it; see tests/Pest.php for the boot. PHPStan still
// reads the source as final and rejects intersection types like
// `QueueInsights & MockInterface`, so we route mocks through the
// Laravel container — `app()->instance()` accepts a `mixed` concrete
// under the QueueInsights binding, sidestepping the type check.
function modalResolver((LegacyMockInterface&MockInterface)|null $svc = null): ModalResolver
{
    $svc ??= Mockery::mock(QueueInsights::class);
    app()->instance(QueueInsights::class, $svc);

    return resolve(ModalResolver::class);
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

it('selectedPending hits the loaded rows without touching the service', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldNotReceive('findPendingByUuid');

    $rows = [
        ['uuid' => 'u-1', 'class' => 'A'],
        ['uuid' => 'u-2', 'class' => 'B'],
    ];

    expect(modalResolver($svc)->selectedPending('u-2', $rows))->toBe($rows[1]);
});

it('selectedPending falls back to the service when the uuid is not in the loaded rows', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $hit = ['uuid' => 'deep-link', 'class' => 'C'];
    $svc->shouldReceive('findPendingByUuid')->with('deep-link')->once()->andReturn($hit);

    expect(modalResolver($svc)->selectedPending('deep-link', []))->toBe($hit);
});

it('selectedPending returns null when both the loaded rows and the service miss', function (): void {
    $svc = Mockery::mock(QueueInsights::class);
    $svc->shouldReceive('findPendingByUuid')->with('aged-out')->once()->andReturnNull();

    expect(modalResolver($svc)->selectedPending('aged-out', []))->toBeNull();
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
