<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Per-connection class roster filter for Prometheus collectors. Three
 * modes (set via `prometheus.class_filter.mode`):
 *
 *   - `allow_all`         — every class in `classes:{connection}`.
 *   - `allow_list`        — only the FQCNs in
 *                           `prometheus.class_filter.classes`. Empty
 *                           list = no per-class metrics. THE DEFAULT.
 *   - `top_n_by_recency`  — top N most-recently-seen classes per
 *                           connection (score on the zset is the
 *                           last-seen unix ts; **not** throughput).
 *
 * Scoped per-connection because `RecordJobProcessed` already pruned
 * the global × cartesian connections fan-out into the per-connection
 * `classes:{connection}` zset. Reading the global zset would re-add
 * that fan-out at scrape time.
 *
 * @internal
 */
final class ClassFilter
{
    public const string MODE_ALLOW_ALL = 'allow_all';

    public const string MODE_ALLOW_LIST = 'allow_list';

    public const string MODE_TOP_N_BY_RECENCY = 'top_n_by_recency';

    /**
     * Per-(mode, connection) memoise so the three class-scoped collectors
     * (jobs_processed, jobs_failed, job_duration) share one ZRANGE / one
     * intersection per scrape, not three. Bound `scoped` in the provider
     * so each request sees a fresh memoise.
     *
     * @var array<string, list<string>>
     */
    private array $memoised = [];

    /**
     * @return list<string>
     */
    public function classesFor(string $connection): array
    {
        $mode = Config::string('prometheus.class_filter.mode', self::MODE_ALLOW_LIST);
        $cacheKey = "{$mode}|{$connection}";

        return $this->memoised[$cacheKey] ?? $this->memoised[$cacheKey] = match ($mode) {
            self::MODE_ALLOW_ALL => $this->allowAll($connection),
            self::MODE_TOP_N_BY_RECENCY => $this->topNByRecency($connection),
            default => $this->allowList($connection),
        };
    }

    /**
     * @return list<string>
     */
    private function allowList(string $connection): array
    {
        // `array_unique` — Prometheus treats two samples with identical
        // `{class,connection}` labels in the same scrape as invalid, so
        // a duplicated FQCN in config can't be allowed to surface twice.
        $allowed = array_values(array_unique(array_filter(
            Config::array('prometheus.class_filter.classes'),
            is_string(...),
        )));
        if ($allowed === []) {
            return [];
        }

        // Intersect against the per-connection roster so a host that
        // allow-lists a class which never ran on a given connection
        // doesn't emit a phantom 0-sample for it.
        $present = $this->allowAll($connection);
        if ($present === []) {
            return [];
        }

        $set = array_flip($present);

        return array_values(array_filter($allowed, static fn (string $c): bool => isset($set[$c])));
    }

    /**
     * @return list<string>
     */
    private function allowAll(string $connection): array
    {
        $raw = $this->redisCommand('zrange', [KeyPrefix::make("classes:{$connection}"), 0, -1]);

        return $this->coerceList($raw);
    }

    /**
     * @return list<string>
     */
    private function topNByRecency(string $connection): array
    {
        $topN = max(1, Config::int('prometheus.class_filter.top_n', 50));
        $raw = $this->redisCommand('zrevrange', [KeyPrefix::make("classes:{$connection}"), 0, $topN - 1]);

        return $this->coerceList($raw);
    }

    /**
     * @return list<string>
     */
    private function coerceList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $args
     */
    private function redisCommand(string $command, array $args): mixed
    {
        return Redis::connection(Config::string('redis_connection', 'default'))
            ->command($command, $args);
    }
}
