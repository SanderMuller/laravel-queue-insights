<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Detectors;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use SanderMuller\QueueInsights\Alerts\Issue;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConfiguredQueueList;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisPipeline;

/**
 * Surfaces dispatcher/worker connection drift on hosts that haven't published
 * `connection_aliases` yet. For each configured queue, walks the host's
 * `config('queue.connections')` map and ZCARDs the `pending-zset:{name}:{q}`
 * key for every name that does NOT resolve (via `ConnectionAlias`) to the
 * canonical connection. A non-zero count under a non-canonical name means
 * jobs are being dispatched on one Laravel queue connection and processed
 * via another against the same physical store — the bug `connection_aliases`
 * fixes.
 *
 * Default OFF (`alerts.rules.connection_drift.enabled = false`). Operators
 * opt in via `config/queue-insights.php` after suspecting drift. Two-DB
 * setups where drift is intentional should leave this rule disabled.
 */
final class ConnectionDriftDetector
{
    public const string RULE = 'connection_drift';

    /**
     * @return list<Issue>
     */
    public function detect(): array
    {
        if (! $this->ruleEnabled()) {
            return [];
        }

        // Index every configured canonical connection by the queue it serves.
        // A single queue name (e.g. `default`) can be served by multiple
        // canonicals (`redis-staging:default` AND `sqs:default`); the detector
        // probes each (candidate, queue) once and reports all canonical
        // configurations for that queue, so it can't pick the wrong alias
        // target by accident.
        $canonicalsByQueue = $this->indexCanonicalsByQueue();
        if ($canonicalsByQueue === []) {
            return [];
        }

        // Build the probe list — every (candidate × canonicalQueue) pair that
        // isn't already alias-collapsed onto one of the configured canonicals.
        // Probes are batched into one pipelined round-trip below.
        $probes = $this->buildProbes($canonicalsByQueue);
        if ($probes === []) {
            return [];
        }

        $replies = RedisPipeline::run($this->redis(), static function (mixed $client) use ($probes): void {
            foreach ($probes as $probe) {
                $client->zcard(KeyPrefix::make("pending-zset:{$probe['candidate']}:{$probe['queue']}"));
            }
        });

        $issues = [];
        foreach ($probes as $i => $probe) {
            $count = $replies[$i] ?? null;
            if (! is_int($count)) {
                continue;
            }

            if ($count <= 0) {
                continue;
            }

            $issues[] = $this->buildIssue($probe['candidate'], $probe['canonicals'], $probe['queue'], $count);
        }

        return $issues;
    }

    /**
     * @return array<string, list<string>>  canonicalQueue → list<canonical connections>
     */
    private function indexCanonicalsByQueue(): array
    {
        $out = [];
        foreach (ConfiguredQueueList::build() as $pair) {
            try {
                $queue = CanonicalQueueKey::fromOrDefault($pair['queue'], $pair['connection']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $out[$queue] ??= [];
            if (! in_array($pair['connection'], $out[$queue], true)) {
                $out[$queue][] = $pair['connection'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $canonicalsByQueue
     * @return list<array{candidate: string, queue: string, canonicals: list<string>}>
     */
    private function buildProbes(array $canonicalsByQueue): array
    {
        $candidates = $this->candidateConnections();
        $probes = [];
        foreach ($canonicalsByQueue as $queue => $canonicals) {
            foreach ($candidates as $candidate) {
                if (in_array(ConnectionAlias::canonical($candidate), $canonicals, true)) {
                    continue;
                }

                $probes[] = ['candidate' => $candidate, 'queue' => $queue, 'canonicals' => $canonicals];
            }
        }

        return $probes;
    }

    public function ruleEnabled(): bool
    {
        return Config::bool('alerts.rules.connection_drift.enabled', false);
    }

    /**
     * Names to probe for stale pending rows. We don't SCAN — bounded by the
     * host's declared queue connections, which is small (typically ≤5) and
     * stable. Aliases the operator already published are filtered out by
     * the canonicalisation check in `detect()`, so an `aliases.redis =
     * redis-staging` mapping silently drops `redis` from the probe set.
     *
     * @return list<string>
     */
    private function candidateConnections(): array
    {
        $configured = config('queue.connections');
        if (! is_array($configured)) {
            return [];
        }

        $names = [];
        foreach (array_keys($configured) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $canonicals  configured canonical connections that serve `$canonicalQueue`
     */
    private function buildIssue(string $candidate, array $canonicals, string $canonicalQueue, int $count): Issue
    {
        // When a single queue is served by multiple configured canonicals,
        // the detector cannot pick the alias target deterministically —
        // operators have to choose. The description lists every candidate
        // canonical so the operator can match against their dispatch shape.
        $canonicalList = "'" . implode("', '", $canonicals) . "'";
        $description = count($canonicals) === 1
            ? sprintf(
                "Pending rows under connection '%s' for queue '%s' but the configured canonical connection is %s. Publish 'connection_aliases' => ['%s' => '%s'] to collapse both sides onto the canonical key, or set this rule's `enabled = false` if the split is intentional.",
                $candidate,
                $canonicalQueue,
                $canonicalList,
                $candidate,
                $canonicals[0],
            )
            : sprintf(
                "Pending rows under connection '%s' for queue '%s'. Multiple canonical connections are configured for this queue: %s — pick the one that matches your dispatch shape and publish `connection_aliases.%s = '<chosen>'`. Or set this rule's `enabled = false` if the split is intentional.",
                $candidate,
                $canonicalQueue,
                $canonicalList,
                $candidate,
            );

        return new Issue(
            rule: self::RULE,
            severity: $this->severity(),
            connection: $candidate,
            queue: $canonicalQueue,
            jobClass: null,
            title: 'Possible connection drift',
            description: $description,
            context: [
                'non_canonical_connection' => $candidate,
                'canonical_connections' => $canonicals,
                'queue' => $canonicalQueue,
                'pending_count' => $count,
            ],
            detectedAt: Date::now()->getTimestamp(),
        );
    }

    private function severity(): AlertSeverity
    {
        return Config::enum(
            'alerts.rules.connection_drift.severity',
            AlertSeverity::class,
            AlertSeverity::Warning,
        );
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
