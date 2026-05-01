<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Symfony\Component\Process\Process;

/**
 * Test seam for spawning `queue:work` subprocesses. The default
 * implementation uses `PHP_BINARY` (a compile-time constant — not
 * overridable any other way) and `base_path('artisan')`. Tests rebind
 * the container binding to a stub that returns a fixture-driven
 * Process so argv assembly + signal/output behaviour can be asserted
 * without spawning a real worker.
 */
interface WorkerProcessFactory
{
    /**
     * @param  list<string>  $queues
     * @param  array<string, string|true>  $forwardedFlags
     */
    public function make(string $connection, array $queues, array $forwardedFlags): Process;
}
