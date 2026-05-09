<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\Scheduler;

use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\StringList;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

/**
 * Per-task roster filter for scheduler-side Prometheus collectors. Two
 * modes (set via `prometheus.task_filter.mode`):
 *
 *   - `allow_all`   — every task in `sched:tasks:order` LIST. THE DEFAULT.
 *   - `allow_list`  — only the taskKeys in `prometheus.task_filter.tasks`.
 *                     Empty list → no scheduler per-task metrics.
 *
 * `top_n_by_recency` from `ClassFilter` is intentionally NOT supported
 * here — task rosters are small (~100 typical) and the source key
 * (`sched:tasks:order`) is a LIST in registration order, not a
 * recency-scored zset. Adding the mode would require shipping a new
 * write path on every `Starting` listener tick.
 *
 * Memoised per (mode) so the eight scheduler collectors share one
 * `LRANGE` per scrape. Bound `scoped` in the provider so the memoise
 * dies with the request.
 *
 * @internal
 */
final class TaskFilter
{
    public const string MODE_ALLOW_ALL = 'allow_all';

    public const string MODE_ALLOW_LIST = 'allow_list';

    /**
     * @var array<string, list<string>>
     */
    private array $memoised = [];

    /**
     * @return list<string>
     */
    public function tasks(): array
    {
        $mode = Config::string('prometheus.task_filter.mode', self::MODE_ALLOW_ALL);

        return $this->memoised[$mode] ?? $this->memoised[$mode] = match ($mode) {
            self::MODE_ALLOW_LIST => $this->allowList(),
            default => $this->allowAll(),
        };
    }

    /**
     * @return list<string>
     */
    private function allowAll(): array
    {
        return StringList::coerce(
            Redis::connection(Config::string('redis_connection', 'default'))
                ->command('lrange', [KeyPrefix::make('sched:tasks:order'), 0, -1]),
        );
    }

    /**
     * @return list<string>
     */
    private function allowList(): array
    {
        // The configured allow-list is the source of truth. We deliberately
        // do NOT intersect against the registered roster (`sched:tasks:order`)
        // — diverging from the queue-side `ClassFilter::allowList` here.
        //
        // Reason: `ScheduleSnapshotter::rebuild` rewrites the roster with
        // `DEL` + per-task `RPUSH`, which is non-atomic. A scrape landing
        // mid-rebuild observes an empty or partial list; intersecting would
        // silently drop every operator-configured task during that window,
        // and during the exact control-plane degradation operators most
        // need their metrics. Hosts that pre-seed snapshots and disable
        // `scheduler.snapshot_rebuild` would lose metrics permanently.
        //
        // Trade-off: a typo'd FQCN in `prometheus.task_filter.tasks` will
        // surface a phantom 0-sample for a never-registered task. Operators
        // notice that faster than missing metrics — preferred failure mode.
        return array_values(array_unique(array_filter(
            Config::array('prometheus.task_filter.tasks'),
            is_string(...),
        )));
    }
}
