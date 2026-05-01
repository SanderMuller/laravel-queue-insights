<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Contracts;

use Illuminate\Queue\Events\JobProcessed;

interface PayloadSanitizer
{
    /**
     * Given the raw job event, return the payload fields to persist (or []).
     * Called ONLY when capture.payloads !== 'off'.
     *
     * @return array<string, scalar|array<mixed>|null>
     */
    public function sanitize(JobProcessed $event): array;
}
