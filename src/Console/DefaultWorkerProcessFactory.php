<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Symfony\Component\Process\Process;

final class DefaultWorkerProcessFactory implements WorkerProcessFactory
{
    /**
     * @param  list<string>  $queues
     * @param  array<string, string|true>  $forwardedFlags
     */
    public function make(string $connection, array $queues, array $forwardedFlags): Process
    {
        $argv = [
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            $connection,
            '--queue=' . implode(',', $queues),
        ];

        foreach ($forwardedFlags as $name => $value) {
            $argv[] = $value === true ? "--{$name}" : "--{$name}={$value}";
        }

        // `timeout: null` overrides Symfony Process's 60-second wall-clock
        // default — daemon workers must run unbounded. The supervisor's
        // grace + SIGKILL path is the only timeout that applies here.
        return new Process($argv, timeout: null);
    }
}
