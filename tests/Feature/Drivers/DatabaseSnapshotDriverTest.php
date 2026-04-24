<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SanderMuller\QueueInsights\Drivers\DatabaseSnapshotDriver;

beforeEach(function (): void {
    config()->set('queue.connections.dbqueue', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Schema::connection('testing')->dropIfExists('jobs');
    Schema::connection('testing')->create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

function insertJob(int $id, string $queue, ?int $reservedAt, int $availableAt, int $createdAt): void
{
    DB::connection('testing')->table('jobs')->insert([
        'id' => $id,
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => $availableAt,
        'created_at' => $createdAt,
    ]);
}

it('counts ready-to-pop rows as depth', function (): void {
    $now = Date::now()->getTimestamp();
    insertJob(1, 'default', null, $now - 5, $now - 60);   // ready
    insertJob(2, 'default', null, $now - 1, $now - 30);   // ready
    insertJob(3, 'default', null, $now + 100, $now);      // delayed (future available_at)

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->depth('default'))->toBe(2)
        ->and($driver->delayed('default'))->toBe(1);
});

it('counts reserved rows as in-flight only while within retry_after', function (): void {
    $now = Date::now()->getTimestamp();
    insertJob(1, 'default', $now - 10, $now - 120, $now - 120); // reserved 10s ago → in-flight
    insertJob(2, 'default', $now - 5, $now - 60, $now - 60);    // reserved 5s ago → in-flight

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->inFlight('default'))->toBe(2)
        ->and($driver->depth('default'))->toBe(0);
});

it('counts a crashed-worker row (reserved beyond retry_after) as depth, not in-flight', function (): void {
    $now = Date::now()->getTimestamp();
    // retry_after = 90s, reservation started 91s ago → poppable again.
    insertJob(1, 'default', $now - 91, $now - 200, $now - 200);

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->inFlight('default'))->toBe(0)
        ->and($driver->depth('default'))->toBe(1);
});

it('handles the retry_after boundary with > / <= semantics', function (): void {
    $now = Date::now()->getTimestamp();
    // reserved_at exactly now - retry_after → moves to depth (<=), not in-flight (>).
    insertJob(1, 'default', $now - 90, $now - 200, $now - 200);

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->inFlight('default'))->toBe(0)
        ->and($driver->depth('default'))->toBe(1);
});

it('honors a non-default retry_after from queue connection config', function (): void {
    config()->set('queue.connections.dbqueue.retry_after', 30);

    $now = Date::now()->getTimestamp();
    // With retry_after=90 this would still be in-flight; with 30 it's depth.
    insertJob(1, 'default', $now - 45, $now - 60, $now - 60);

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->inFlight('default'))->toBe(0)
        ->and($driver->depth('default'))->toBe(1);
});

it('counts future-dated rows as delayed', function (): void {
    $now = Date::now()->getTimestamp();
    insertJob(1, 'default', null, $now + 60, $now);
    insertJob(2, 'default', null, $now + 120, $now);
    insertJob(3, 'default', null, $now - 1, $now);   // ready, not delayed

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->delayed('default'))->toBe(2)
        ->and($driver->depth('default'))->toBe(1);
});

it('scopes counts by queue name', function (): void {
    $now = Date::now()->getTimestamp();
    insertJob(1, 'default', null, $now - 5, $now - 60);
    insertJob(2, 'high', null, $now - 5, $now - 60);
    insertJob(3, 'high', null, $now - 5, $now - 60);

    $driver = new DatabaseSnapshotDriver('dbqueue');

    expect($driver->depth('default'))->toBe(1)
        ->and($driver->depth('high'))->toBe(2);
});
