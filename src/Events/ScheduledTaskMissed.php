<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Events;

use Illuminate\Console\Scheduling\Event;

final readonly class ScheduledTaskMissed
{
    public function __construct(
        public string $taskKey,
        public Event $task,
        public int $expectedAtMs,
    ) {}
}
