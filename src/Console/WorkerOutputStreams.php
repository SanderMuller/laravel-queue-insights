<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

/**
 * Test seam for the parent stdout / stderr stream resources used by
 * the supervisor's line-prefixing output sink. Default impl returns
 * PHP's `STDOUT` / `STDERR` constants — the stream resources opened by
 * the engine for the command line, not the C `STDOUT_FILENO` /
 * `STDERR_FILENO` integers. Tests rebind to `php://memory` resources
 * so the supervisor's prefixed output can be captured + asserted.
 */
interface WorkerOutputStreams
{
    /**
     * @return resource
     */
    public function stdout(): mixed;

    /**
     * @return resource
     */
    public function stderr(): mixed;
}
