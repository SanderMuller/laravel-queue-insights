<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Database\Query\Builder;

/**
 * Read-side filter that drops silenced job-class failures from dashboard
 * surfaces, the failure_rate detector, and outbound notifications.
 *
 * Counter writes are preserved by the listeners — silencing is reversible
 * without losing history. The helper is request-scoped (bound via
 * `$this->app->scoped(SilencedJobs::class)` in the service provider) so
 * the config snapshot is fresh per request under FPM and cleanly reset
 * between requests under Octane. No static state to flush.
 */
final class SilencedJobs
{
    /**
     * @var array<string, true>
     */
    private array $set;

    public function __construct()
    {
        $list = config('queue-insights.silenced', []);
        $this->set = array_fill_keys(
            is_array($list) ? array_values(array_filter($list, is_string(...))) : [],
            true,
        );
    }

    public function isSilenced(string $class): bool
    {
        // ResolveJobClass can return an empty string in fallback paths; treat
        // that as "not silenced" rather than risk a falsy lookup matching an
        // empty-string config entry.
        return $class !== '' && isset($this->set[$class]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->set);
    }

    /**
     * Append per-silenced-class `LOWER(payload) NOT LIKE` clauses to a
     * failed-jobs query so silenced classes drop out of the result set.
     * Cost is O(silenced) `NOT LIKE` clauses per query; the optimiser
     * collapses these into a single table scan.
     */
    public function appendExclusion(Builder $query): void
    {
        foreach (array_keys($this->set) as $class) {
            $pattern = DisplayNamePayloadMatch::pattern($class);
            if ($pattern !== null) {
                $query->whereRaw('LOWER(payload) NOT LIKE ? ESCAPE ?', $pattern);
            }
        }
    }
}
