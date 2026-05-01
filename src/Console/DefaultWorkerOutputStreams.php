<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

final class DefaultWorkerOutputStreams implements WorkerOutputStreams
{
    public function stdout(): mixed
    {
        return STDOUT;
    }

    public function stderr(): mixed
    {
        return STDERR;
    }
}
