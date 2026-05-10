<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

/**
 * URL-bound filter state captured at retry-invocation time, pre-sanitised
 * via Support\AuditFieldSanitizer. The action carries this verbatim into
 * the audit-log payload — no re-sanitisation downstream.
 *
 * @internal
 */
final readonly class AuditContext
{
    public function __construct(
        public int|string|null $userId,
        public string $scopeConnection,
        public string $filterConnection,
        public string $filterQueue,
        public string $filterClass,
        public string $filterFrom,
        public string $filterTo,
    ) {}
}
