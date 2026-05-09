<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Scheduler;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Per-task `sched:counters:{task}` reader, memoised per scrape.
 *
 * Five scheduler collectors (`runs_total`, `runtime_sum`,
 * `last_run_timestamp`, `hung_total`, `missed_total`) all read fields
 * from the same hash. Without sharing, they issue 5 × N HMGET/HGET
 * round-trips per scrape (500 with 100 tasks). This class collapses
 * the fan-out: one `HGETALL` per task per scrape, the result memoised
 * on the instance, and individual collectors call {@see field()} for
 * the field they need.
 *
 * Bound `scoped` in the provider so the memoise dies with the request
 * — a fresh instance per scrape sees fresh values from Redis.
 *
 * @internal
 */
final class CountersReader
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $memoised = [];

    public function field(string $task, string $field): ?string
    {
        $hash = $this->hash($task);

        return isset($hash[$field]) && is_string($hash[$field]) ? $hash[$field] : null;
    }

    /**
     * Read the full counters hash for a task. Returns `[]` when the
     * hash is missing — collectors must treat absent fields as the
     * canonical zero / omit-sample case (per family-specific policy).
     *
     * @return array<string, string>
     */
    public function hash(string $task): array
    {
        if (array_key_exists($task, $this->memoised)) {
            return $this->memoised[$task];
        }

        $raw = Redis::connection(Config::string('redis_connection', 'default'))
            ->command('hgetall', [KeyPrefix::make("sched:counters:{$task}")]);

        if (! is_array($raw)) {
            return $this->memoised[$task] = [];
        }

        // Both phpredis and Predis return HGETALL as a `field => value` map
        // when the connection is configured for associative responses (the
        // package default). Defensive normalise: keep only string entries.
        $out = [];
        foreach ($raw as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $out[$field] = $value;
            }
        }

        return $this->memoised[$task] = $out;
    }
}
