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
            foreach ($this->batch()->run($pairs) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($this->detectClassScopedMany($this->queueInsights->jobClasses()) as $issue) {
            $issues[] = $issue;
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
        return $this->detectClassScopedMany([$class]);
    }

    /**
     * Class-scoped rules for every class, with the failure-rate reads for
     * all of them in one pipelined round trip.
     *
     * @param  list<string>  $classes
     * @return list<Issue>
     */
    public function detectClassScopedMany(array $classes): array
    {
        $failures = $this->failureRateDetector->detectMany($classes);

        $issues = [];
        foreach ($classes as $class) {
            if (isset($failures[$class])) {
                $issues[] = $failures[$class];
            }

            $slow = $this->slowP95Detector->detect($class);
            if ($slow instanceof Issue) {
                $issues[] = $slow;
            }
        }

        return $issues;
    }

    /**
     * Snapshot-command path: one queue through the pipelined batch, with the
     * driver's depth used in place of the `live:depth` read.
     *
     * @return list<Issue>
     */
    public function detectForSnapshot(string $connection, string $canonicalQueue, int $depth): array
    {
        return $this->batch()->run([[$connection, $canonicalQueue]], [$depth]);
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

    private function batch(): IssueDetectorBatch
    {
        return new IssueDetectorBatch(
            $this->depthDetector,
            $this->stalledDetector,
            $this->oldestPendingDetector,
            $this->stuckInFlightDetector,
            $this->snapshotErroredDetector,
            $this->backlogGrowingDetector,
        );
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
