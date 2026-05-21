<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Listeners\RecordJobQueued;

/**
 * Test support job that, from inside its own `handle()`, drives the
 * `RecordJobQueued` listener for a nested job. The call site the listener
 * resolves for that nested job must point at the `dispatchNested()` line
 * BELOW — i.e. this parent job's dispatch line — exactly the "which of the
 * two flows dispatched it" attribution Phase 2 is built for.
 */
final class InitiatorDispatchingJob
{
    /**
     * Run the JobQueued listener as if a job were dispatched from inside
     * this method. Returns the child uuid so the test can read the
     * resolved call site back off `qi:initiator:{uuid}`.
     */
    public function dispatchNested(): string
    {
        $uuid = '01ARZ3NDEKTSV4RRFFQ69' . Str::upper(Str::random(6));

        $payload = json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\\Jobs\\NestedDispatchedJob',
        ]);

        (new RecordJobQueued())->handle(new JobQueued(
            connectionName: 'redis',
            queue: 'work',
            id: 'driver-id-' . Str::random(8),
            job: (object) ['displayName' => 'App\\Jobs\\NestedDispatchedJob'],
            payload: $payload === false ? '' : $payload,
            delay: null,
        ));

        return $uuid;
    }
}
