<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A queueable job used to exercise real `dispatch()` / `Job::dispatch()`
 * flows so the `JobQueued` event actually fires and `RecordJobQueued`
 * resolves a genuine call site through the framework dispatch path.
 */
final class InitiatorQueuedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // No-op — the job is queued, never run, in these tests.
    }
}
