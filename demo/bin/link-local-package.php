<?php declare(strict_types=1);

/*
 * Replace demo/vendor/sandermuller/laravel-queue-insights (a real-dir copy
 * created by composer's path-repo with `symlink: false`) with a symlink
 * pointing at the repo root, so local edits in src/ reflect immediately
 * without re-running `composer update`.
 *
 * Skipped on Laravel Cloud + CI — those environments need the real-dir
 * copy semantics composer's path-repo gives them, and Cloud's container
 * may not have the parent dir laid out the way a relative symlink expects.
 *
 * Non-destructive: probes symlink support at a temporary path BEFORE
 * removing the composer copy, and only swaps in the link once creation
 * is known to succeed. If symlink isn't supported (Windows without
 * developer mode, filesystems without symlink support), the helper logs
 * to STDERR and leaves the composer copy intact — the demo still runs,
 * just without live edits.
 */

if (getenv('LARAVEL_CLOUD') !== false || getenv('CI') !== false) {
    return;
}

$target = __DIR__ . '/../vendor/sandermuller/laravel-queue-insights';
$expectedRoot = realpath(__DIR__ . '/../..');

if ($expectedRoot === false) {
    fwrite(STDERR, "[demo] could not resolve repo root from " . __DIR__ . "\n");

    return;
}

// If a symlink is already in place, validate it points where we expect.
// A stale link (repo moved, link replaced) would otherwise cause the demo
// to silently run against the wrong codebase across composer runs.
if (is_link($target)) {
    $resolved = realpath($target);

    if ($resolved === $expectedRoot) {
        return;
    }

    // Stale or mis-targeted — unlink and relink below.
    @unlink($target);
}

if (! is_dir(dirname($target))) {
    return;
}

// Probe symlink support at a temp path FIRST so we can bail without
// having destroyed the composer-installed copy if creation fails.
$tmp = $target . '.link.tmp.' . getmypid();
@unlink($tmp);

if (! @symlink('../../..', $tmp)) {
    fwrite(STDERR, "[demo] symlink not supported here; keeping composer copy of sandermuller/laravel-queue-insights\n");

    return;
}

// Probe survived — now safe to remove the copy + swap in the link.
if (is_dir($target) && ! is_link($target)) {
    $cmd = PHP_OS_FAMILY === 'Windows'
        ? 'rmdir /S /Q ' . escapeshellarg(str_replace('/', '\\', $target))
        : 'rm -rf ' . escapeshellarg($target);
    exec($cmd, $_, $code);

    if ($code !== 0) {
        @unlink($tmp);
        fwrite(STDERR, "[demo] failed to remove copied package dir at {$target}; composer copy preserved\n");

        return;
    }
}

if (! @rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "[demo] failed to move symlink into place at {$target}; composer copy was already removed — re-run `composer install` to restore\n");

    return;
}

echo "[demo] linked sandermuller/laravel-queue-insights -> repo root for live edits\n";
