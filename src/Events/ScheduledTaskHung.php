<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

use Illuminate\Console\Scheduling\Event;

final readonly class ScheduledTaskHung
{
    public function __construct(
        public string $taskKey,
        public string $runId,
        public ?Event $task,
        public int $startedAtMs,
        public int $elapsedSeconds,
    ) {}
}
