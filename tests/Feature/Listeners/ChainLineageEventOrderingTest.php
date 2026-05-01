<?php declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use SanderMuller\QueueInsights\Tests\Support\ChainChildJob;
use SanderMuller\QueueInsights\Tests\Support\ChainGrandchildJob;
use SanderMuller\QueueInsights\Tests\Support\ChainParentJob;

beforeEach(function (): void {
    // Sync driver skips JobQueued, so the ordering claim cannot be
    // exercised on it. Use the database driver against the in-memory
    // sqlite — fires JobQueued at push and lets us drive the worker
    // explicitly to fire JobProcessing/JobProcessed in order.
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

function drainChain(int $expectedJobs): void
{
    // `app('queue.worker')` is the canonical way to grab Laravel's worker —
    // Worker's constructor takes uninjectable callables (isDownForMaintenance,
    // resetScope) so `resolve(Worker::class)` (which Rector tries to apply)
    // cannot autowire it. Resolve via the container alias instead and narrow
    // the typed return locally.
    // Resolve via the Application facade — bypasses Rector's
    // `LaravelContainerStringToFullyQualifiedName` rule, which would
    // otherwise rewrite an `app('queue.worker')` call into
    // `resolve(Worker::class)` and break at runtime (Worker's constructor
    // takes uninjectable callables).
    $worker = App::make('queue.worker');
    if (! $worker instanceof Worker) {
        throw new RuntimeException('queue.worker is not bound to a Worker instance');
    }

    for ($i = 0; $i < $expectedJobs; ++$i) {
        $worker->runNextJob('database', 'default', new WorkerOptions());
    }
}

/**
 * Phase 0 spike — locks the framework event-ordering assumption that the
 * claim-ticket design depends on:
 *
 *   parent.JobProcessing  <  child.JobQueued  <  parent.JobProcessed
 *
 * If this assertion ever fails after a Laravel upgrade, the claim-ticket
 * write side must move (e.g. to the JobQueueing/Queueing edge of the parent)
 * before the chain feature can ship on that version.
 */
it('parent JobProcessing fires before child JobQueued fires before parent JobProcessed', function (): void {
    $log = new ChainEventLog();
    $log->listen();

    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    drainChain(2);

    $parentProcessing = $log->requireIndex('JobProcessing', ChainParentJob::class);
    $childQueued = $log->requireIndex('JobQueued', ChainChildJob::class);
    $parentProcessed = $log->requireIndex('JobProcessed', ChainParentJob::class);

    expect($parentProcessing)->toBeLessThan($childQueued)
        ->and($childQueued)->toBeLessThan($parentProcessed);
});

it('multi-link chain dispatches each child inside the prior parent fire window', function (): void {
    $log = new ChainEventLog();
    $log->listen();

    Bus::chain([new ChainParentJob(), new ChainChildJob(), new ChainGrandchildJob()])->dispatch();
    drainChain(3);

    $parentProcessing = $log->requireIndex('JobProcessing', ChainParentJob::class);
    $childQueued = $log->requireIndex('JobQueued', ChainChildJob::class);
    $parentProcessed = $log->requireIndex('JobProcessed', ChainParentJob::class);

    $childProcessing = $log->requireIndex('JobProcessing', ChainChildJob::class);
    $grandchildQueued = $log->requireIndex('JobQueued', ChainGrandchildJob::class);
    $childProcessed = $log->requireIndex('JobProcessed', ChainChildJob::class);

    // Parent → Child link
    expect($parentProcessing)->toBeLessThan($childQueued)
        ->and($childQueued)->toBeLessThan($parentProcessed)
        // Child → Grandchild link
        ->and($childProcessing)->toBeLessThan($grandchildQueued)
        ->and($grandchildQueued)->toBeLessThan($childProcessed);
});

/**
 * Phase 0 finding (logged in Findings section of the spec): the spec's §2.2
 * assumption that the child's serialized command carries `chainConnection` /
 * `chainQueue` is FALSE for chains dispatched via `Bus::chain([...])->dispatch()`
 * without explicit `->onConnection()` / `->onQueue()`.
 *
 * Reason: SerializesModels::__serialize strips properties whose runtime value
 * equals the declared default. `chainConnection` / `chainQueue` default to
 * null; on a vanilla `Bus::chain()` dispatch they remain null and get stripped.
 * `chained` is set to `[]` on the LAST link in a chain — also equal to its
 * declared default `[]`, also stripped.
 *
 * The only Queueable property reliably carried in chain dispatches is
 * `chainCatchCallbacks` (set to `[]` by PendingChain::dispatch line 211 and
 * propagated by Queueable::dispatchNextJobInChain line 341). Default is `null`,
 * `[]` differs, so it survives serialization.
 *
 * Implication for Phase 1: the read-side cannot use chainConnection/chainQueue
 * as the "I am a chained child" gate. Either (a) use chainCatchCallbacks
 * presence (note: also fires on root), or (b) attempt RPOP on every JobQueued
 * and treat misses as the common case. (b) is simpler and the cost is one
 * cache RPOP per non-chain dispatch.
 */
it('child JobQueued serialized command preserves only non-default Queueable props', function (): void {
    $payloads = [];
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$payloads): void {
        if (! is_string($event->payload) || $event->payload === '') {
            return;
        }

        $decoded = json_decode($event->payload, true);
        if (! is_array($decoded)) {
            return;
        }

        $name = $decoded['displayName'] ?? null;
        if (is_string($name)) {
            $payloads[$name] = $decoded;
        }
    });

    Bus::chain([new ChainParentJob(), new ChainChildJob()])->dispatch();
    drainChain(2);

    expect($payloads)->toHaveKey(ChainChildJob::class);

    $clean = decodeSerializedProps(commandFor($payloads, ChainChildJob::class));

    // The reliable chain-child signal: chainCatchCallbacks survives because
    // PendingChain seeds it to [] (≠ null default) and dispatchNextJobInChain
    // propagates it onto every link.
    expect($clean)->toHaveKey('chainCatchCallbacks')
        ->and($clean['chainCatchCallbacks'])->toBeArray();

    // Last-link confirmation: chained is empty so SerializesModels strips it.
    expect($clean)->not->toHaveKey('chained');
});

it('intermediate child JobQueued payload preserves a non-empty chained tail', function (): void {
    $payloads = [];
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$payloads): void {
        if (! is_string($event->payload) || $event->payload === '') {
            return;
        }

        $decoded = json_decode($event->payload, true);
        if (! is_array($decoded)) {
            return;
        }

        $name = $decoded['displayName'] ?? null;
        if (is_string($name)) {
            $payloads[$name] = $decoded;
        }
    });

    Bus::chain([new ChainParentJob(), new ChainChildJob(), new ChainGrandchildJob()])->dispatch();
    drainChain(3);

    $clean = decodeSerializedProps(commandFor($payloads, ChainChildJob::class));

    // Intermediate link: chained holds the remaining tail (one entry).
    $chained = $clean['chained'] ?? null;
    expect($chained)->toBeArray()
        ->and($chained)->toHaveCount(1);
});

/**
 * Decode a Laravel-serialized job command without instantiating, returning
 * the property map keyed by clean property name (null-byte prefixes stripped).
 *
 * @return array<string, mixed>
 */
function decodeSerializedProps(string $serialized): array
{
    $decoded = unserialize($serialized, ['allowed_classes' => false]);
    if (! is_object($decoded)) {
        return [];
    }

    $arr = (array) $decoded;
    $clean = [];
    foreach ($arr as $key => $value) {
        $strKey = (string) $key;
        if ($strKey === '__PHP_Incomplete_Class_Name') {
            continue;
        }

        $parts = explode("\0", $strKey);
        $clean[$parts[count($parts) - 1]] = $value;
    }

    return $clean;
}

/**
 * Pluck the serialized `data.command` for a given displayName from a JobQueued
 * payload map. Throws on a miss so the test fails loudly instead of decoding
 * an empty string.
 *
 * @param  array<string, array<array-key, mixed>>  $payloads
 */
function commandFor(array $payloads, string $displayName): string
{
    $entry = $payloads[$displayName] ?? null;
    if (! is_array($entry)) {
        throw new RuntimeException("missing JobQueued payload for [{$displayName}]");
    }

    $data = $entry['data'] ?? null;
    if (! is_array($data)) {
        throw new RuntimeException("payload for [{$displayName}] has no data array");
    }

    $command = $data['command'] ?? null;
    if (! is_string($command) || $command === '') {
        throw new RuntimeException("payload for [{$displayName}] has no serialized command");
    }

    return $command;
}

/**
 * Captures Laravel's three job-lifecycle events into a typed log so the
 * Phase 0 ordering test can assert sequencing without fighting PHPStan over
 * `mixed` Closure return types.
 */
final class ChainEventLog
{
    /** @var list<array{type: string, class: string}> */
    private array $events = [];

    public function listen(): void
    {
        Event::listen(JobQueued::class, function (JobQueued $event): void {
            if (! is_string($event->payload) || $event->payload === '') {
                return;
            }

            $payload = json_decode($event->payload, true);
            if (! is_array($payload) || ! isset($payload['displayName']) || ! is_string($payload['displayName'])) {
                return;
            }

            $this->events[] = ['type' => 'JobQueued', 'class' => $payload['displayName']];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->events[] = ['type' => 'JobProcessing', 'class' => $event->job->resolveName()];
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->events[] = ['type' => 'JobProcessed', 'class' => $event->job->resolveName()];
        });
    }

    /**
     * First occurrence of an (event-type, class) pair. Throws on miss so the
     * assertion site reads cleanly with a typed `int`.
     */
    public function requireIndex(string $type, string $class): int
    {
        foreach ($this->events as $i => $entry) {
            if ($entry['type'] === $type && $entry['class'] === $class) {
                return $i;
            }
        }

        throw new RuntimeException("expected event {$type}({$class}) was not fired");
    }
}
