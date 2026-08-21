<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use SanderMuller\QueueInsights\Alerts\Detectors\BacklogGrowingDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\ConnectionDriftDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\DepthDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\FailureRateDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\OldestPendingDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\SlowP95Detector;
use SanderMuller\QueueInsights\Alerts\Detectors\SnapshotErroredDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\StalledDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\StuckInFlightDetector;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Pure detector engine — runs every enabled detector against current Redis
 * state and returns the active Issue list. No cooldown, no events, no channels.
 * Used by both the snapshot command (via IssueDispatcher) and the dashboard.
 */
final readonly class IssueDetector
{
    public function __construct(
        private DepthDetector $depthDetector,
        private StalledDetector $stalledDetector,
        private OldestPendingDetector $oldestPendingDetector,
        private StuckInFlightDetector $stuckInFlightDetector,
        private SnapshotErroredDetector $snapshotErroredDetector,
        private BacklogGrowingDetector $backlogGrowingDetector,
        private FailureRateDetector $failureRateDetector,
        private SlowP95Detector $slowP95Detector,
        private ConnectionDriftDetector $connectionDriftDetector,
        private QueueInsights $queueInsights,
    ) {}

    /**
     * @return list<Issue>
     */
    public function detectAll(): array
    {
        $issues = [];

        $pairs = $this->queueScope();
        if ($pairs !== []) {
            $batch = new IssueDetectorBatch(
                $this->depthDetector,
                $this->stalledDetector,
                $this->oldestPendingDetector,
                $this->stuckInFlightDetector,
                $this->snapshotErroredDetector,
                $this->backlogGrowingDetector,
            );
            foreach ($batch->run($pairs) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($this->queueInsights->jobClasses() as $class) {
            foreach ($this->detectClassScoped($class) as $issue) {
                $issues[] = $issue;
            }
        }

        // Global enumerator — opt-in, default off. Walks every configured
        // queue × every host queue connection so it can't fit the per-pair
        // batch; emits its own Issues directly.
        foreach ($this->connectionDriftDetector->detect() as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function jobClasses(): array
    {
        return $this->queueInsights->jobClasses();
    }

    /**
     * @return list<Issue>
     */
    public function detectClassScoped(string $class): array
    {
        $issues = [];

        $failure = $this->failureRateDetector->detect($class);
        if ($failure instanceof Issue) {
            $issues[] = $failure;
        }

        $slow = $this->slowP95Detector->detect($class);
        if ($slow instanceof Issue) {
            $issues[] = $slow;
        }

        return $issues;
    }

    /**
     * Snapshot-command path: the depth value is already in hand from the
     * driver, so the depth detector skips its live:depth round trip. Other
     * detectors run normally.
     *
     * @return list<Issue>
     */
    public function detectForSnapshot(string $connection, string $canonicalQueue, int $depth): array
    {
        return $this->detectQueueScoped($connection, $canonicalQueue, depth: $depth);
    }

    /**
     * Run only the snapshot_errored detector for a single (connection, queue).
     * Called from the snapshot command's catch branch immediately after the
     * `snapshot:error:{c}:{q}` key has been written, so the detector sees a
     * freshly-set key and returns the issue.
     */
    public function detectSnapshotError(string $connection, string $canonicalQueue): ?Issue
    {
        return $this->snapshotErroredDetector->detect($connection, $canonicalQueue);
    }

    /**
     * @return list<Issue>
     */
    private function detectQueueScoped(string $connection, string $canonicalQueue, ?int $depth): array
    {
        $issues = [];

        $depthIssue = $depth === null
            ? $this->depthDetector->detect($connection, $canonicalQueue)
            : $this->depthDetector->detectWithDepth($connection, $canonicalQueue, $depth);
        if ($depthIssue instanceof Issue) {
            $issues[] = $depthIssue;
        }

        $stalled = $this->stalledDetector->detect($connection, $canonicalQueue);
        if ($stalled instanceof Issue) {
            $issues[] = $stalled;
        }

        $oldest = $this->oldestPendingDetector->detect($connection, $canonicalQueue);
        if ($oldest instanceof Issue) {
            $issues[] = $oldest;
        }

        $stuck = $this->stuckInFlightDetector->detect($connection, $canonicalQueue);
        if ($stuck instanceof Issue) {
            $issues[] = $stuck;
        }

        $errored = $this->snapshotErroredDetector->detect($connection, $canonicalQueue);
        if ($errored instanceof Issue) {
            $issues[] = $errored;
        }

        $backlog = $this->backlogGrowingDetector->detect($connection, $canonicalQueue);
        if ($backlog instanceof Issue) {
            $issues[] = $backlog;
        }

        return $issues;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function queueScope(): array
    {
        $pairs = [];

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            if ($queue === '') {
                continue;
            }

            $pairs[] = [$connection, CanonicalQueueKey::forConnection($queue, $connection)];
        }

        return $pairs;
    }
}
