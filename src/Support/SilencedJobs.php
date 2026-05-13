<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Read-side filter that drops silenced job-class failures from dashboard
 * surfaces, the failure_rate detector, and outbound notifications.
 *
 * Counter writes are preserved by the listeners — silencing is reversible
 * without losing history. The helper is request-scoped (bound via
 * `$this->app->scoped(SilencedJobs::class)` in the service provider) so
 * the config snapshot is fresh per request under FPM and cleanly reset
 * between requests under Octane. No static state to flush.
 *
 * Two match modes:
 *  - exact `silenced` list (O(1) hash lookup, cheap)
 *  - `silenced_patterns` globs (O(patterns) `Str::is` fallback)
 *
 * Exact-match wins when both lists are populated — patterns are the
 * fallback path only.
 *
 * **Case-insensitive matching everywhere.** The SQL exclusion path
 * (`DisplayNamePayloadMatch`) lowercases both sides so the URL-filter
 * input stays robust against deep-link casing drift; this helper
 * mirrors that by lowercasing on lookup so `'app\\jobs\\foo'` in
 * config matches `'App\\Jobs\\Foo'` on the listener side. Storage
 * keeps the operator's original casing for `all()` / `patterns()`
 * display.
 */
final readonly class SilencedJobs
{
    /**
     * Operator-supplied class list, original casing preserved for
     * display via `all()`.
     *
     * @var list<string>
     */
    private array $classes;

    /**
     * Lowercased exact-match set for O(1) lookup. Keys are
     * `strtolower($class)`.
     *
     * @var array<string, true>
     */
    private array $lowerSet;

    /**
     * Operator-supplied glob list, original casing preserved for
     * display via `patterns()`.
     *
     * @var list<string>
     */
    private array $patterns;

    /**
     * Lowercased glob list, used for `Str::is` matching after the
     * input class is lowercased.
     *
     * @var list<string>
     */
    private array $lowerPatterns;

    /**
     * Lowercased Horizon-sourced silences merged into `lowerSet` for match
     * lookups. Stored separately so `all()` keeps the operator-editable list
     * uncoupled from upstream packages that self-register silences via
     * `config('horizon.silenced')` (e.g. spatie/laravel-health's
     * `silence_health_queue_job` flag → `HealthQueueJob::class`).
     *
     * @var list<string>
     */
    private array $horizonClasses;

    public function __construct()
    {
        $list = config('queue-insights.silenced', []);
        $this->classes = is_array($list)
            ? array_values(array_filter($list, is_string(...)))
            : [];

        // Merge `horizon.silenced` so packages that silence themselves via
        // Horizon (spatie/laravel-health writes to `horizon.silenced` at boot;
        // operators add entries via `config/horizon.php`) take effect in our
        // dashboard / detectors / notifications without a duplicate
        // `queue-insights.silenced` entry. Merge is read-only — we never write
        // back to the Horizon config.
        $horizon = config('horizon.silenced', []);
        $this->horizonClasses = is_array($horizon)
            ? array_values(array_filter($horizon, is_string(...)))
            : [];

        $this->lowerSet = array_fill_keys(
            array_map(
                strtolower(...),
                [...$this->classes, ...$this->horizonClasses],
            ),
            true,
        );

        $patterns = config('queue-insights.silenced_patterns', []);
        $this->patterns = is_array($patterns)
            ? array_values(array_filter($patterns, is_string(...)))
            : [];
        $this->lowerPatterns = array_map(strtolower(...), $this->patterns);
    }

    public function isSilenced(string $class): bool
    {
        // ResolveJobClass can return an empty string in fallback paths; treat
        // that as "not silenced" rather than risk a falsy lookup matching an
        // empty-string config entry.
        if ($class === '') {
            return false;
        }

        $lower = strtolower($class);

        if (isset($this->lowerSet[$lower])) {
            return true;
        }

        foreach ($this->lowerPatterns as $pattern) {
            if (Str::is($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact-match list (operator + Horizon, deduped). Patterns are not
     * enumerable, so callers that need to "iterate every silenced class"
     * (e.g. the Silenced dashboard tab) must combine this list with
     * `isSilenced()` for pattern coverage — see
     * `DashboardData::buildSilencedListings`.
     *
     * Includes upstream `horizon.silenced` entries so the Silenced tab
     * surfaces classes packages have self-registered (otherwise an operator
     * who never touched `queue-insights.silenced` would see an empty tab
     * even though detectors / dashboard filters are correctly suppressing
     * Horizon-silenced classes).
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->horizonClasses === []) {
            return $this->classes;
        }

        $seen = array_fill_keys(array_map(strtolower(...), $this->classes), true);
        $merged = $this->classes;
        foreach ($this->horizonClasses as $class) {
            $lower = strtolower($class);
            if (! isset($seen[$lower])) {
                $seen[$lower] = true;
                $merged[] = $class;
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function hasAny(): bool
    {
        return $this->classes !== []
            || $this->horizonClasses !== []
            || $this->patterns !== [];
    }

    /**
     * Append per-silenced-class `LOWER(payload) NOT LIKE` clauses to a
     * failed-jobs query so silenced classes drop out of the result set.
     * Cost is O(silenced + patterns) `NOT LIKE` clauses per query; the
     * optimiser collapses these into a single table scan.
     */
    public function appendExclusion(Builder $query): void
    {
        // Operator + Horizon classes both contribute exclusions — otherwise
        // detectors / dashboard filters would suppress a class while the
        // failed-jobs SQL query still rendered it.
        foreach ([...$this->classes, ...$this->horizonClasses] as $class) {
            $pattern = DisplayNamePayloadMatch::pattern($class);
            if ($pattern !== null) {
                $query->whereRaw('LOWER(payload) NOT LIKE ? ESCAPE ?', $pattern);
            }
        }

        foreach ($this->patterns as $glob) {
            $pattern = DisplayNamePayloadMatch::patternFromGlob($glob);
            if ($pattern !== null) {
                $query->whereRaw('LOWER(payload) NOT LIKE ? ESCAPE ?', $pattern);
            }
        }
    }
}
