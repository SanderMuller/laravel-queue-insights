<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Resolves the application dispatch call site from a backtrace.
 *
 * Walks `debug_backtrace()` frames top-down and returns the first
 * **application** `file:line` — the line that issued the `dispatch()` — as
 * a base-path-relative string (`app/Services/Billing.php:88`).
 *
 * Frames are filtered by their `file` path, NOT by namespace: the
 * `dispatch()` helper frame carries no `class`, so namespace matching
 * alone would miss it. A frame is skipped when its file is:
 *
 *   - under the `vendor/` directory, or
 *   - under this package's own source root (`src/`) — filtering on
 *     `vendor/` alone is insufficient because a host may pull QI via a
 *     Composer path repository or a symlinked checkout, so QI's
 *     `realpath`-resolved source sits OUTSIDE `vendor/`. Under Testbench
 *     the package source is the repo root, not `vendor/`, for the same
 *     reason, or
 *   - the framework's global `helpers.php`.
 *
 * The skip-paths set is computed once from the runtime layout but is also
 * injectable so tests can supply their own roots.
 */
final class CallSiteResolver
{
    /**
     * Absolute, `realpath`-normalised directory/file prefixes whose frames
     * are skipped during the walk.
     *
     * @var list<string>
     */
    private array $skipPaths;

    /**
     * @param  list<string>|null  $skipPaths  Override the computed skip-paths
     *                                        set; null → compute from the
     *                                        runtime layout (vendor dir +
     *                                        this package's own source root +
     *                                        the framework global helpers).
     */
    public function __construct(?array $skipPaths = null)
    {
        $this->skipPaths = $skipPaths === null
            ? self::computeSkipPaths()
            : self::normalisePaths($skipPaths);
    }

    /**
     * Walk a bounded backtrace and return the first application frame
     * formatted as `relative/path.php:LINE`, or `null` when no application
     * frame is found within `$maxDepth` (e.g. a chained job's 2nd+ link,
     * which is queued by the worker's chain machinery — no app frame).
     */
    public function resolve(int $maxDepth): ?string
    {
        $depth = $maxDepth > 0 ? $maxDepth : 1;

        // DEBUG_BACKTRACE_IGNORE_ARGS keeps the snapshot cheap and avoids
        // retaining references to job arguments; the depth cap bounds cost.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (! is_string($file) || $file === '' || ! is_int($line)) {
                continue;
            }

            if ($this->isSkipped($file)) {
                continue;
            }

            return $this->formatRelative($file) . ':' . $line;
        }

        return null;
    }

    /**
     * True when the given absolute file path sits under any skip-path
     * prefix. Paths are `realpath`-normalised so a symlinked checkout
     * resolves to the same canonical prefix the skip-set holds.
     */
    private function isSkipped(string $file): bool
    {
        $resolved = realpath($file);
        $candidate = $resolved === false ? $file : $resolved;

        foreach ($this->skipPaths as $skip) {
            if ($candidate === $skip || str_starts_with($candidate, $skip . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip the application base path so the call site is reported
     * `app/...:88` rather than as an absolute path. A file outside the
     * base path keeps its absolute path — a reasonable fallback for the
     * rare dispatch from outside the project tree.
     */
    private function formatRelative(string $file): string
    {
        $base = self::resolvedBasePath();
        if ($base === null) {
            return $file;
        }

        if ($file === $base) {
            return $file;
        }

        $prefix = $base . DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $prefix)) {
            return substr($file, strlen($prefix));
        }

        return $file;
    }

    /**
     * Compute the default skip-paths: the Composer `vendor/` directory,
     * this package's own `src/` root (derived from this file's location,
     * `realpath`-resolved so a symlinked / path-repo install is still
     * excluded), and the framework's global `helpers.php`.
     *
     * @return list<string>
     */
    private static function computeSkipPaths(): array
    {
        $paths = [];

        // This package's own source root — `dirname(__DIR__)` of this file
        // is `src/`. realpath() so symlinked / Composer path-repo installs
        // (QI source outside vendor/) are still excluded.
        $packageSrc = realpath(dirname(__DIR__));
        if (is_string($packageSrc)) {
            $paths[] = $packageSrc;
        }

        // The Composer vendor directory. Resolved from the installed
        // `illuminate/support` location when available — robust against
        // path-repo layouts where base_path()/vendor doesn't exist.
        foreach (self::candidateVendorDirs() as $vendor) {
            $resolved = realpath($vendor);
            if (is_string($resolved)) {
                $paths[] = $resolved;
            }
        }

        // Framework global helpers.php — `dispatch()` lives here and the
        // frame carries no class, so it can't be skipped by namespace.
        if (function_exists('dispatch')) {
            try {
                $reflection = new \ReflectionFunction('dispatch');
                $helperFile = $reflection->getFileName();
                if (is_string($helperFile) && $helperFile !== '') {
                    $resolved = realpath($helperFile);
                    if (is_string($resolved)) {
                        $paths[] = $resolved;
                    }
                }
            } catch (\ReflectionException) {
                // Helper not reflectable — vendor/ skip already covers it.
            }
        }

        return self::normalisePaths($paths);
    }

    /**
     * Candidate `vendor/` directory locations. The base-path/vendor is the
     * common case; the package root's own vendor covers a standalone
     * checkout / Testbench layout.
     *
     * @return list<string>
     */
    private static function candidateVendorDirs(): array
    {
        $dirs = [];

        $base = self::resolvedBasePath();
        if ($base !== null) {
            $dirs[] = $base . DIRECTORY_SEPARATOR . 'vendor';
        }

        // dirname(__DIR__, 2) is the package root; its vendor/ holds the
        // dev dependencies under a standalone checkout.
        $dirs[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';

        return $dirs;
    }

    /**
     * The application base path, `realpath`-normalised, or null when the
     * `base_path()` helper is unavailable (e.g. a unit context with no
     * booted application).
     */
    private static function resolvedBasePath(): ?string
    {
        if (! function_exists('base_path')) {
            return null;
        }

        $resolved = realpath(base_path());

        return is_string($resolved) ? $resolved : null;
    }

    /**
     * `realpath`-normalise a list of path strings, dropping any that don't
     * resolve and any empties.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function normalisePaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $resolved = realpath($path);
            $candidate = $resolved === false ? $path : $resolved;

            if (! in_array($candidate, $out, true)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
