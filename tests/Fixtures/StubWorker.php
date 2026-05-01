<?php declare(strict_types=1);

/**
 * Test stub child for `queue-insights:work` fan-out + signal tests.
 * Behaviour is env-driven so the test can configure the exit code,
 * sleep duration, output, and SIGTERM handling without baking choices
 * into argv (argv stays reserved for asserting the supervisor's
 * `queue:work` argv shape).
 *
 * Recognised env (all optional):
 *   STUB_OUT      string  — line written to stdout (terminating \n appended)
 *   STUB_ERR      string  — line written to stderr (terminating \n appended)
 *   STUB_PARTIAL  string  — bytes written to stdout WITHOUT trailing \n
 *                           (exercises the prefixer's split-line carry buffer)
 *   STUB_EXIT     int     — exit code (default 0)
 *   STUB_SLEEP    int     — seconds to sleep before exit (default 0)
 *   STUB_TRAP     "1"     — install SIGTERM handler that prints
 *                           `caught:SIGTERM` to stdout and exits 143
 *   STUB_IGNORE_TERM "1"  — set SIGTERM to SIG_IGN so only SIGKILL ends
 *                           the process (used by grace-expiry tests)
 */
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);

    if (getenv('STUB_TRAP') === '1') {
        pcntl_signal(SIGTERM, function (): void {
            fwrite(STDOUT, "caught:SIGTERM\n");
            exit(143);
        });
    } elseif (getenv('STUB_IGNORE_TERM') === '1') {
        pcntl_signal(SIGTERM, SIG_IGN);
    }
}

$out = getenv('STUB_OUT');
if (is_string($out) && $out !== '') {
    fwrite(STDOUT, $out . "\n");
}

$err = getenv('STUB_ERR');
if (is_string($err) && $err !== '') {
    fwrite(STDERR, $err . "\n");
}

$partial = getenv('STUB_PARTIAL');
if (is_string($partial) && $partial !== '') {
    fwrite(STDOUT, $partial);
}

$sleep = getenv('STUB_SLEEP');
if (is_string($sleep) && is_numeric($sleep) && (int) $sleep > 0) {
    sleep((int) $sleep);
}

$exit = getenv('STUB_EXIT');
exit(is_string($exit) && is_numeric($exit) ? (int) $exit : 0);
