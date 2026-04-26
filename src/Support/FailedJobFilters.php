<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Immutable value object holding the filter inputs applied to the
 * `Recent failed` table on the dashboard.
 *
 * Empty strings = "no filter on that field". The class field is matched
 * against the JSON payload via anchored substring LIKE (see
 * specs/horizon-inspired-features.md §1.3) — exact-match semantics here
 * would force operators to type long FQCNs from memory.
 *
 * Date inputs are expected as `Y-m-d` (the wire format every browser
 * submits for `<input type="date">`, regardless of display locale).
 */
final readonly class FailedJobFilters
{
    public function __construct(
        public string $connection = '',
        public string $queue = '',
        public string $class = '',
        public string $from = '',
        public string $to = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->connection === ''
            && $this->queue === ''
            && $this->class === ''
            && $this->from === ''
            && $this->to === '';
    }
}
