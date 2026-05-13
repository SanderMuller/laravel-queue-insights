<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\Detectors\BacklogGrowingDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\DepthDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\OldestPendingDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\SnapshotErroredDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\StalledDetector;
use SanderMuller\QueueInsights\Alerts\Detectors\StuckInFlightDetector;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisPipeline;
use SanderMuller\QueueInsights\Support\ZsetHead;

/**
 * Pipelined fan-out across every configured (connection, queue) pair for
 * `IssueDetector::detectAll`. Replaces the per-queue sequential read
 * pattern: 5–6 Redis calls per queue × N queues used to be N sequential
 * round-trips per detectAll call. The dashboard's wire:poll path runs
 * detectAll on every cache miss (5 s TTL) so on an 8-queue tenant the
 * Redis cost was ~24 round-trips/second.
 *
 * This class collapses the whole fan-out into two pipelined round-trips:
 *   - Phase 1: every enabled rule's per-queue base read enqueued together.
 *   - Phase 2: the per-uuid `HGET pending:{uuid} class` lookups for the
 *     oldest_pending / stuck_inflight heads that crossed their age
 *     thresholds.
 *
 * Each detector still owns its own `evaluate()` (Issue construction +
 * config reads); the batch only stages the I/O and feeds responses back.
 *
 * @internal
 */
final readonly class IssueDetectorBatch
{
    public function __construct(
        private DepthDetector $depthDetector,
        private StalledDetector $stalledDetector,
        private OldestPendingDetector $oldestPendingDetector,
        private StuckInFlightDetector $stuckInFlightDetector,
        private SnapshotErroredDetector $snapshotErroredDetector,
        private BacklogGrowingDetector $backlogGrowingDetector,
    ) {}

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return list<Issue>
     */
    public function run(array $pairs): array
    {
        $flags = $this->collectEnabledFlags();
        if (! in_array(true, $flags, true)) {
            return [];
        }

        $now = Date::now()->getTimestamp();
        $stalledThreshold = $flags['stalled'] ? $now - $this->stalledDetector->idleSeconds() : 0;

        $redis = $this->redis();

        $phase1 = $this->runPhase1($redis, $pairs, $flags, $now, $stalledThreshold);
        $stride = $this->stride($flags);

        [$perPair, $classLookups] = $this->decodePhase1($pairs, $phase1, $stride, $flags, $now);

        $classByPair = $this->runPhase2($redis, $classLookups);

        return $this->buildIssues($pairs, $perPair, $classByPair, $flags, $now);
    }

    /**
     * @return array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}
     */
    private function collectEnabledFlags(): array
    {
        return [
            'depth' => $this->depthDetector->ruleEnabled(),
            'stalled' => $this->stalledDetector->ruleEnabled(),
            'oldest' => $this->oldestPendingDetector->ruleEnabled(),
            'stuck' => $this->stuckInFlightDetector->ruleEnabled(),
            'errored' => $this->snapshotErroredDetector->ruleEnabled(),
            'backlog' => $this->backlogGrowingDetector->ruleEnabled(),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     * @return list<mixed>
     */
    private function runPhase1(RedisConnection $redis, array $pairs, array $flags, int $now, int $stalledThreshold): array
    {
        return RedisPipeline::run($redis, static function (mixed $client) use ($pairs, $flags, $now, $stalledThreshold): void {
            foreach ($pairs as [$c, $q]) {
                if ($flags['depth'] || $flags['stalled']) {
                    $client->get(KeyPrefix::make("live:depth:{$c}:{$q}"));
                }

                if ($flags['stalled']) {
                    $client->zcount(KeyPrefix::make("wait:{$c}:{$q}"), (string) $stalledThreshold, '+inf');
                }

                if ($flags['oldest']) {
                    $client->zrangebyscore(
                        KeyPrefix::make("pending-zset:{$c}:{$q}"),
                        '-inf',
                        (string) $now,
                        ['LIMIT' => [0, 1], 'WITHSCORES' => true],
                    );
                }

                if ($flags['stuck']) {
                    $client->zrange(KeyPrefix::make("inflight-zset:{$c}:{$q}"), 0, 0, ['WITHSCORES' => true]);
                }

                if ($flags['errored']) {
                    $client->get(KeyPrefix::make("snapshot:error:{$c}:{$q}"));
                }

                if ($flags['backlog']) {
                    $client->zrange(KeyPrefix::make("samples:depth:{$c}:{$q}"), 0, -1, ['WITHSCORES' => true]);
                }
            }
        });
    }

    /**
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     */
    private function stride(array $flags): int
    {
        $stride = 0;
        if ($flags['depth'] || $flags['stalled']) {
            ++$stride;
        }

        foreach (['stalled', 'oldest', 'stuck', 'errored', 'backlog'] as $key) {
            if ($flags[$key]) {
                ++$stride;
            }
        }

        return $stride;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @param  list<mixed>  $phase1
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     * @return array{0: array<int, array{depthRaw: mixed, recent: mixed, oldestHead: ?array{0: string, 1: float|int}, stuckHead: ?array{0: string, 1: float|int}, errorRaw: mixed, samples: list<array{0: int, 1: int}>}>, 1: list<array{idx: int, kind: 'oldest'|'stuck', uuid: string}>}
     */
    private function decodePhase1(array $pairs, array $phase1, int $stride, array $flags, int $now): array
    {
        $oldestThreshold = $flags['oldest'] ? $this->oldestPendingDetector->thresholdSeconds() : 0;
        $stuckThreshold = $flags['stuck'] ? $this->stuckInFlightDetector->thresholdSeconds() : 0;

        $perPair = [];
        $classLookups = [];

        foreach (array_keys($pairs) as $i) {
            $state = $this->decodePairSlice($phase1, $i * $stride, $flags);
            $this->collectClassLookups($state, $i, $now, $oldestThreshold, $stuckThreshold, $classLookups);
            $perPair[$i] = $state;
        }

        return [$perPair, $classLookups];
    }

    /**
     * @param  list<mixed>  $phase1
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     * @return array{depthRaw: mixed, recent: mixed, oldestHead: ?array{0: string, 1: float|int}, stuckHead: ?array{0: string, 1: float|int}, errorRaw: mixed, samples: list<array{0: int, 1: int}>}
     */
    private function decodePairSlice(array $phase1, int $offset, array $flags): array
    {
        $state = [
            'depthRaw' => null,
            'recent' => null,
            'oldestHead' => null,
            'stuckHead' => null,
            'errorRaw' => null,
            'samples' => [],
        ];
        $cursor = 0;

        if ($flags['depth'] || $flags['stalled']) {
            $state['depthRaw'] = $phase1[$offset + $cursor] ?? null;
            ++$cursor;
        }

        if ($flags['stalled']) {
            $state['recent'] = $phase1[$offset + $cursor] ?? null;
            ++$cursor;
        }

        if ($flags['oldest']) {
            $state['oldestHead'] = ZsetHead::firstMemberScore($phase1[$offset + $cursor] ?? null);
            ++$cursor;
        }

        if ($flags['stuck']) {
            $state['stuckHead'] = ZsetHead::firstMemberScore($phase1[$offset + $cursor] ?? null);
            ++$cursor;
        }

        if ($flags['errored']) {
            $state['errorRaw'] = $phase1[$offset + $cursor] ?? null;
            ++$cursor;
        }

        if ($flags['backlog']) {
            $state['samples'] = BacklogGrowingDetector::decodeSamples($phase1[$offset + $cursor] ?? null);
        }

        return $state;
    }

    /**
     * @param  array{depthRaw: mixed, recent: mixed, oldestHead: ?array{0: string, 1: float|int}, stuckHead: ?array{0: string, 1: float|int}, errorRaw: mixed, samples: list<array{0: int, 1: int}>}  $state
     * @param  list<array{idx: int, kind: 'oldest'|'stuck', uuid: string}>  $classLookups
     * @param-out list<array{idx: int, kind: 'oldest'|'stuck', uuid: string}>  $classLookups
     */
    private function collectClassLookups(array $state, int $i, int $now, int $oldestThreshold, int $stuckThreshold, array &$classLookups): void
    {
        if ($state['oldestHead'] !== null) {
            $age = $now - (int) $state['oldestHead'][1];
            if ($age >= $oldestThreshold) {
                $classLookups[] = ['idx' => $i, 'kind' => 'oldest', 'uuid' => $state['oldestHead'][0]];
            }
        }

        if ($state['stuckHead'] !== null) {
            $age = $now - (int) $state['stuckHead'][1];
            if ($age >= $stuckThreshold) {
                $classLookups[] = ['idx' => $i, 'kind' => 'stuck', 'uuid' => $state['stuckHead'][0]];
            }
        }
    }

    /**
     * @param  list<array{idx: int, kind: 'oldest'|'stuck', uuid: string}>  $classLookups
     * @return array{oldest: array<int, ?string>, stuck: array<int, ?string>}
     */
    private function runPhase2(RedisConnection $redis, array $classLookups): array
    {
        $out = ['oldest' => [], 'stuck' => []];
        if ($classLookups === []) {
            return $out;
        }

        $phase2 = RedisPipeline::run($redis, static function (mixed $client) use ($classLookups): void {
            foreach ($classLookups as $lookup) {
                $client->hget(KeyPrefix::make("pending:{$lookup['uuid']}"), 'class');
            }
        });

        foreach ($classLookups as $k => $lookup) {
            $value = $phase2[$k] ?? null;
            $out[$lookup['kind']][$lookup['idx']] = is_string($value) && $value !== '' ? $value : null;
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @param  array<int, array{depthRaw: mixed, recent: mixed, oldestHead: ?array{0: string, 1: float|int}, stuckHead: ?array{0: string, 1: float|int}, errorRaw: mixed, samples: list<array{0: int, 1: int}>}>  $perPair
     * @param  array{oldest: array<int, ?string>, stuck: array<int, ?string>}  $classByPair
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     * @return list<Issue>
     */
    private function buildIssues(array $pairs, array $perPair, array $classByPair, array $flags, int $now): array
    {
        $issues = [];
        foreach ($pairs as $i => [$c, $q]) {
            foreach ($this->evaluatePair($c, $q, $perPair[$i], $classByPair, $flags, $now, $i) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param  array{depthRaw: mixed, recent: mixed, oldestHead: ?array{0: string, 1: float|int}, stuckHead: ?array{0: string, 1: float|int}, errorRaw: mixed, samples: list<array{0: int, 1: int}>}  $state
     * @param  array{oldest: array<int, ?string>, stuck: array<int, ?string>}  $classByPair
     * @param  array{depth: bool, stalled: bool, oldest: bool, stuck: bool, errored: bool, backlog: bool}  $flags
     * @return list<Issue>
     */
    private function evaluatePair(string $c, string $q, array $state, array $classByPair, array $flags, int $now, int $i): array
    {
        $issues = [];
        $depthInt = (is_string($state['depthRaw']) || is_numeric($state['depthRaw'])) ? (int) $state['depthRaw'] : null;

        if ($flags['depth']) {
            $issues[] = $this->depthDetector->evaluate($c, $q, $depthInt);
        }

        if ($flags['stalled']) {
            $issues[] = $this->stalledDetector->evaluate($c, $q, $state['depthRaw'], $state['recent'], $now);
        }

        if ($flags['oldest']) {
            $issues[] = $this->oldestPendingDetector->evaluate($c, $q, $state['oldestHead'], $classByPair['oldest'][$i] ?? null, $now);
        }

        if ($flags['stuck']) {
            $issues[] = $this->stuckInFlightDetector->evaluate($c, $q, $state['stuckHead'], $classByPair['stuck'][$i] ?? null, $now);
        }

        if ($flags['errored']) {
            $issues[] = $this->snapshotErroredDetector->evaluate($c, $q, $state['errorRaw']);
        }

        if ($flags['backlog']) {
            $issues[] = $this->backlogGrowingDetector->evaluate($c, $q, $state['samples']);
        }

        return array_values(array_filter($issues, static fn (?Issue $issue): bool => $issue instanceof Issue));
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
