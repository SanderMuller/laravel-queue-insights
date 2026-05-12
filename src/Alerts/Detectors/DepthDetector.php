<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

final class DepthDetector
{
    public const string RULE = 'depth';

    public function detect(string $connection, string $canonicalQueue): ?Issue
    {
        if (! $this->ruleEnabled()) {
            return null;
        }

        $depth = $this->readDepth($connection, $canonicalQueue);
        if ($depth === null) {
            return null;
        }

        $match = $this->resolveMatch($connection, $canonicalQueue, $depth);
        if ($match === null) {
            return null;
        }

        return new Issue(
            rule: self::RULE,
            severity: $match['severity'],
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Queue depth exceeded',
            description: "Queue {$connection}:{$canonicalQueue} depth {$depth} ≥ threshold {$match['depth']}.",
            context: [
                'depth' => $depth,
                'threshold' => $match['depth'],
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    /** Snapshot-command path — avoids the live:depth round trip. */
    public function detectWithDepth(string $connection, string $canonicalQueue, int $depth): ?Issue
    {
        if (! $this->ruleEnabled()) {
            return null;
        }

        $match = $this->resolveMatch($connection, $canonicalQueue, $depth);
        if ($match === null) {
            return null;
        }

        return new Issue(
            rule: self::RULE,
            severity: $match['severity'],
            connection: $connection,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Queue depth exceeded',
            description: "Queue {$connection}:{$canonicalQueue} depth {$depth} ≥ threshold {$match['depth']}.",
            context: [
                'depth' => $depth,
                'threshold' => $match['depth'],
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    private function ruleEnabled(): bool
    {
        // Legacy config (alerts.thresholds at top level) implicitly enables the depth rule.
        if (Config::array('alerts.thresholds') !== []) {
            return true;
        }

        return Config::bool('alerts.rules.depth.enabled', true);
    }

    private function readDepth(string $connection, string $canonicalQueue): ?int
    {
        $key = KeyPrefix::make("live:depth:{$connection}:{$canonicalQueue}");
        $value = $this->redis()->command('get', [$key]);

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{depth: int, severity: AlertSeverity}|null
     */
    private function resolveMatch(string $connection, string $canonicalQueue, int $depth): ?array
    {
        $best = null;
        $bestRank = -1;

        foreach ($this->thresholdEntries() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['connection'] ?? null) !== $connection) {
                continue;
            }

            $queue = $entry['queue'] ?? null;
            $entryDepth = $entry['depth'] ?? null;
            if (! is_string($queue)) {
                continue;
            }

            if (! is_int($entryDepth)) {
                continue;
            }

            if (CanonicalQueueKey::from($queue) !== $canonicalQueue) {
                continue;
            }

            if ($depth < $entryDepth) {
                continue;
            }

            $severity = AlertSeverity::fromConfig($entry['severity'] ?? null);
            $rank = $severity->rank();

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = ['depth' => $entryDepth, 'severity' => $severity];
            }
        }

        return $best;
    }

    /**
     * @return array<int, mixed>
     */
    private function thresholdEntries(): array
    {
        $legacy = Config::array('alerts.thresholds');
        if ($legacy !== []) {
            return array_values($legacy);
        }

        return array_values(Config::array('alerts.rules.depth.thresholds'));
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
