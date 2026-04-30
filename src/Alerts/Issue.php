<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use SanderMuller\QueueInsights\Enums\AlertSeverity;

/**
 * Internal value object for the alerts pipeline. Detectors construct
 * `Issue` and `IssueDispatcher` translates each one into a host-facing
 * event (where `severity` is exposed as a `string` for backwards-compat).
 *
 * @internal
 */
final readonly class Issue
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $rule,
        public AlertSeverity $severity,
        public string $connection,
        public string $queue,
        public ?string $jobClass,
        public string $title,
        public string $description,
        public array $context,
        public int $detectedAt,
    ) {}

    public function cooldownKeySuffix(): string
    {
        if ($this->jobClass !== null) {
            return "alert:cooldown:{$this->rule}:class:{$this->jobClass}";
        }

        return "alert:cooldown:{$this->rule}:{$this->connection}:{$this->queue}";
    }
}
