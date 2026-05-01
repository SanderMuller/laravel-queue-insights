<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use LogicException;
use SanderMuller\QueueInsights\Support\Config;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Multi-connection queue worker supervisor. Reads
 * `queue-insights.snapshots`, groups entries by connection, and spawns
 * one `queue:work` subprocess per connection with `--queue=` carrying
 * the comma-joined queue list (Laravel's built-in priority order).
 *
 * The supervisor owns: argv assembly, line-prefixed child output,
 * SIGTERM/SIGINT/SIGQUIT forwarding to live children, a configurable
 * grace window followed by SIGKILL escalation, and Bash-convention
 * `128 + signum` exit code propagation. Restart-on-crash and other
 * liveness concerns belong to the host process manager (systemd,
 * supervisord, docker).
 */
final class QueueInsightsWorkCommand extends Command
{
    protected $signature = 'queue-insights:work
        {--connection=* : Restrict to one or more connections (repeat the flag, comma-separated values, or both)}
        {--tries= : Number of times to attempt a job before logging it as failed}
        {--timeout= : The number of seconds a child process can run before being terminated}
        {--memory= : The memory limit in megabytes}
        {--sleep= : Number of seconds to sleep when no job is available}
        {--rest= : Number of seconds to rest between jobs}
        {--max-jobs= : Number of jobs to process before stopping}
        {--max-time= : Maximum number of seconds to run for}
        {--backoff= : Number of seconds to wait before retrying a job}
        {--name= : The name forwarded verbatim to each child queue:work}
        {--once : Only process the next job on the queue}
        {--stop-when-empty : Stop when the queue is empty}
        {--force : Force the worker to run even in maintenance mode}';

    protected $description = 'Run one queue:work child per monitored connection from queue-insights.snapshots.';

    /**
     * Names of value-bearing flags forwarded verbatim to each child.
     *
     * @var list<string>
     */
    private const array VALUE_FLAGS = [
        'tries',
        'timeout',
        'memory',
        'sleep',
        'rest',
        'max-jobs',
        'max-time',
        'backoff',
        'name',
    ];

    /**
     * Names of boolean flags forwarded (presence only) to each child.
     *
     * @var list<string>
     */
    private const array BOOL_FLAGS = [
        'once',
        'stop-when-empty',
        'force',
    ];

    public function __construct(
        private readonly WorkerProcessFactory $factory,
        private readonly WorkerOutputStreams $streams,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! extension_loaded('pcntl')) {
            $this->components->error(
                'queue-insights:work requires the pcntl extension. POSIX hosts must enable it; '
                . 'Windows is not a supported runtime target for this command. Refusing to boot.'
            );

            return self::FAILURE;
        }

        $map = $this->buildMap();

        if ($map === []) {
            $this->components->error(
                'queue-insights:work: no monitored queues configured (queue-insights.snapshots is empty).'
            );

            return self::FAILURE;
        }

        $filter = $this->resolveConnectionFilter();

        if ($filter !== null) {
            $configured = array_keys($map);
            $map = $filter === [] ? [] : array_intersect_key($map, array_flip($filter));

            if ($map === []) {
                // Distinguish "supplied but parsed to nothing" (e.g.
                // `--connection=,` / whitespace / env-expanded empty)
                // from "supplied a real name that doesn't match any
                // configured connection." Same exit-non-zero failure
                // shape; the error text helps the operator see the
                // footgun.
                $supplied = $filter === [] ? '(empty)' : implode(',', $filter);

                $this->components->error(sprintf(
                    'queue-insights:work: --connection=%s matched no monitored connections. Configured: %s',
                    $supplied,
                    implode(', ', $configured),
                ));

                return self::FAILURE;
            }
        }

        $forwarded = $this->collectForwardedFlags();

        $processes = $this->buildProcesses($map, $forwarded);

        $this->components->info(sprintf(
            'Booting %d connection(s): %s',
            count($processes),
            implode(', ', array_map(
                static fn (string $c): string => sprintf('%s (%s)', $c, implode(',', $map[$c])),
                array_keys($processes),
            )),
        ));

        $prefixer = new WorkerOutputPrefixer(
            $this->streams->stdout(),
            $this->streams->stderr(),
        );

        return $this->supervise($processes, $prefixer);
    }

    /**
     * Spawn every process, stream prefixed output, propagate exit codes
     * per §2.4, and forward `SIGTERM`/`SIGINT`/`SIGQUIT` to live children
     * with a grace + `SIGKILL` escalation per §2.3.
     *
     * - First non-zero child triggers `SIGTERM` to the remaining live
     *   children; the parent exit code is the **first** non-zero child's.
     * - External signal triggers the same teardown path; the parent
     *   exit code becomes `128 + signum` (Bash convention) when no
     *   child failure preceded the signal.
     * - After `shutdown_grace_seconds` elapses with survivors still
     *   running, send `SIGKILL` and write a warning line listing the
     *   killed connections.
     *
     * @param  array<string, Process>  $processes
     */
    private function supervise(array $processes, WorkerOutputPrefixer $prefixer): int
    {
        /** @var array<string, int> $exits */
        $exits = [];
        $firstFailure = null;
        $teardownIssued = false;
        $teardownStartedAt = null;
        $signalReceived = null;
        $killEscalated = false;

        // Capture pre-existing signal handlers + async-signal state so
        // we can restore them on exit. CLI artisan dies after handle()
        // returns so the leak is invisible there — but Octane / Pest
        // / any long-lived host that runs the command in-process would
        // otherwise inherit our handlers (closures with stale by-ref
        // captures of the now-defunct `$exits` / `$processes`) and
        // mishandle later signals.
        $previousAsync = pcntl_async_signals();
        $previousHandlers = [
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
            SIGINT => pcntl_signal_get_handler(SIGINT),
            SIGQUIT => pcntl_signal_get_handler(SIGQUIT),
        ];

        try {
            // Install signal handlers BEFORE starting children. Without
            // this ordering, a SIGTERM arriving in the microsecond gap
            // between `startProcesses()` and the handler install would
            // hit PHP's default disposition ("terminate parent") and
            // leave already-spawned children as orphans. The handler
            // closure safely no-ops while iterating not-yet-started
            // Processes (their `isRunning()` returns false).
            $this->installSignalHandlers($processes, $exits, $signalReceived, $teardownIssued, $teardownStartedAt);

            // Transactional startup: tracks the actually-started subset
            // and aborts further launches on a mid-loop signal or a
            // `Process::start()` exception. The wait/teardown loop
            // operates on the started subset so a partial start can't
            // hang forever waiting on never-spawned children.
            $started = $this->startProcesses($processes, $prefixer, $signalReceived);

            // If a signal landed during install→start, the handler
            // captured it but workers weren't running yet (or only some
            // were). Re-issue the same teardown now that we know which
            // children are alive so we don't spin the wait loop on
            // workers we already meant to stop.
            if ($signalReceived !== null) {
                $this->terminateLiveChildren($started, $exits, $signalReceived);
            }

            // Nothing started — exit straight through resolveExitCode so
            // a signal-aborted boot still propagates `128 + signum`.
            if ($started === []) {
                return $this->resolveExitCode($firstFailure, $signalReceived);
            }

            $graceSeconds = Config::int('work.shutdown_grace_seconds', 120);

            while (count($exits) < count($started)) {
                $this->reapExitedChildren($started, $exits, $prefixer, $firstFailure);

                if ($firstFailure !== null && ! $teardownIssued) {
                    $teardownIssued = true;
                    $teardownStartedAt = microtime(true);
                    $this->terminateLiveChildren($started, $exits, SIGTERM);
                }

                if ($teardownIssued && ! $killEscalated && $teardownStartedAt !== null && microtime(true) - $teardownStartedAt > $graceSeconds) {
                    $this->escalateKill($started, $exits, $graceSeconds);
                    $killEscalated = true;
                }

                if (count($exits) < count($started)) {
                    Sleep::usleep(100_000);
                }
            }

            return $this->resolveExitCode($firstFailure, $signalReceived);
        } finally {
            foreach ($previousHandlers as $sig => $handler) {
                pcntl_signal($sig, $handler);
            }

            pcntl_async_signals($previousAsync);
        }
    }

    /**
     * Transactional startup: starts each child in order, but stops
     * immediately on `$signalReceived` so a SIGTERM during boot doesn't
     * spawn more workers than necessary, and synchronously SIGKILLs
     * the already-started subset if any `Process::start()` throws so a
     * mid-boot failure can't leave orphaned workers consuming jobs
     * under an exiting parent.
     *
     * Returns the (possibly partial) subset of children that did start
     * — the caller's wait loop iterates this subset, not the full
     * intended set, so a partial start never hangs on a never-spawned
     * child.
     *
     * @param  array<string, Process>  $processes
     * @return array<string, Process>
     */
    private function startProcesses(array $processes, WorkerOutputPrefixer $prefixer, ?int &$signalReceived): array
    {
        /** @var array<string, Process> $started */
        $started = [];

        try {
            foreach ($processes as $connection => $process) {
                if ($signalReceived !== null) {
                    // Operator pulled the plug mid-boot. Don't launch
                    // any further workers; let the caller forward the
                    // signal to whatever's already up.
                    break;
                }

                $process->start(function (string $type, string $buffer) use ($connection, $prefixer): void {
                    $prefixer->append($connection, $type, $buffer);
                });
                $started[$connection] = $process;
            }
        } catch (Throwable $throwable) {
            // Mid-boot start failure: synchronously SIGKILL the subset
            // that did spawn before propagating. Without this, the
            // exception unwinds through `supervise()`'s finally,
            // restores the previous signal handlers, and leaves the
            // already-started workers running orphaned under a
            // crashed/exiting supervisor.
            foreach ($started as $earlyChild) {
                try {
                    if ($earlyChild->isRunning()) {
                        $earlyChild->signal(SIGKILL);
                    }
                } catch (LogicException) {
                    // Already gone — nothing to do.
                }
            }

            throw $throwable;
        }

        return $started;
    }

    /**
     * Install async-delivered signal handlers that forward the received
     * signal to every live child and start the grace timer. Without
     * `pcntl_async_signals(true)`, handlers would only fire at
     * dispatch points and SIGTERM forwarding would lag a poll tick.
     *
     * @param  array<string, Process>  $processes
     * @param  array<string, int>  $exits
     */
    private function installSignalHandlers(
        array $processes,
        array &$exits,
        ?int &$signalReceived,
        bool &$teardownIssued,
        ?float &$teardownStartedAt,
    ): void {
        pcntl_async_signals(true);

        $handler = function (int $sig) use ($processes, &$exits, &$signalReceived, &$teardownIssued, &$teardownStartedAt): void {
            // Idempotent — repeat signals during the grace window must
            // not reset the timer.
            if ($signalReceived !== null) {
                return;
            }

            $signalReceived = $sig;
            $teardownIssued = true;
            $teardownStartedAt = microtime(true);
            $this->terminateLiveChildren($processes, $exits, $sig);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGQUIT, $handler);
    }

    /**
     * Drain exited children, flush their tail output, and capture the
     * first non-zero exit code as the parent's eventual exit value.
     *
     * @param  array<string, Process>  $processes
     * @param  array<string, int>  $exits
     */
    private function reapExitedChildren(
        array $processes,
        array &$exits,
        WorkerOutputPrefixer $prefixer,
        ?int &$firstFailure,
    ): void {
        foreach ($processes as $connection => $process) {
            if (array_key_exists($connection, $exits)) {
                continue;
            }

            if ($process->isRunning()) {
                continue;
            }

            // Final blocking wait drains pipe bytes through the
            // streaming callback so the prefixer's carry buffer holds
            // the true tail before we flush it.
            $process->wait();

            $code = $process->getExitCode() ?? 0;
            $exits[$connection] = $code;
            $prefixer->flush($connection);

            $this->components->info(sprintf('[%s] worker exited %d', $connection, $code));

            if ($code !== 0 && $firstFailure === null) {
                $firstFailure = $code;
            }
        }
    }

    /**
     * @param  array<string, Process>  $processes
     * @param  array<string, int>  $exits
     */
    private function terminateLiveChildren(array $processes, array $exits, int $signal): void
    {
        foreach ($processes as $connection => $process) {
            if (array_key_exists($connection, $exits)) {
                continue;
            }

            // `Process::signal()` throws `LogicException` when the child
            // exits between our `isRunning()` check and the signal
            // dispatch. The race window is microseconds but real under
            // async signal delivery; treat the post-exit no-op as
            // benign rather than crashing the supervisor mid-teardown.
            try {
                if ($process->isRunning()) {
                    $process->signal($signal);
                }
            } catch (LogicException) {
                // Child already gone; let the next reapExitedChildren()
                // tick record the exit code.
            }
        }
    }

    /**
     * @param  array<string, Process>  $processes
     * @param  array<string, int>  $exits
     */
    private function escalateKill(array $processes, array $exits, int $graceSeconds): void
    {
        $killed = [];

        foreach ($processes as $connection => $process) {
            if (array_key_exists($connection, $exits)) {
                continue;
            }

            // Same race-with-exit as `terminateLiveChildren()` — the
            // child can exit between `isRunning()` and `signal()`.
            // Treat that as "no need to SIGKILL after all" silently.
            try {
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                    $killed[] = $connection;
                }
            } catch (LogicException) {
                // Child already gone before we landed the kill.
            }
        }

        if ($killed !== []) {
            fwrite($this->streams->stderr(), sprintf(
                "queue-insights:work: grace window (%ds) expired, sent SIGKILL to: %s\n",
                $graceSeconds,
                implode(', ', $killed),
            ));
        }
    }

    /**
     * Bash convention: 128 + signum for signal-initiated exits. Lets
     * systemd / supervisord distinguish "operator stopped me" from
     * "supervisor crashed" without scraping logs.
     */
    private function resolveExitCode(?int $firstFailure, ?int $signalReceived): int
    {
        if ($firstFailure !== null) {
            return $firstFailure;
        }

        if ($signalReceived !== null) {
            return 128 + $signalReceived;
        }

        return self::SUCCESS;
    }

    /**
     * Build the (connection => [queue, ...]) map from snapshots config,
     * preserving config order (Laravel's --queue= comma list is processed
     * left-to-right as priority).
     *
     * @return array<string, list<string>>
     */
    private function buildMap(): array
    {
        $map = [];

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if ($connection === '') {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            if ($queue === '') {
                continue;
            }

            $map[$connection] ??= [];

            if (! in_array($queue, $map[$connection], true)) {
                $map[$connection][] = $queue;
            }
        }

        return $map;
    }

    /**
     * Resolve `--connection=` into a deduped first-seen list. Accepts
     * both `--connection=foo --connection=bar` (Symfony VALUE_IS_ARRAY)
     * and `--connection=foo,bar` (CSV per value); the two forms compose.
     *
     * Returns `null` only when the option was NOT supplied. When it WAS
     * supplied but parsing produced zero tokens (e.g. `--connection=,`,
     * `--connection=" "`, an env-expanded empty string in a deploy
     * script), returns an empty list — `handle()` then fails closed
     * rather than silently fanning out to every monitored connection.
     *
     * @return list<string>|null
     */
    private function resolveConnectionFilter(): ?array
    {
        $raw = $this->option('connection');

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $out = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                if (in_array($part, $out, true)) {
                    continue;
                }

                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string|true>
     */
    private function collectForwardedFlags(): array
    {
        $out = [];

        foreach (self::VALUE_FLAGS as $name) {
            $value = $this->option($name);

            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }

        foreach (self::BOOL_FLAGS as $name) {
            if ($this->option($name) === true) {
                $out[$name] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $map
     * @param  array<string, string|true>  $forwarded
     * @return array<string, Process>
     */
    private function buildProcesses(array $map, array $forwarded): array
    {
        $processes = [];

        foreach ($map as $connection => $queues) {
            $processes[$connection] = $this->factory->make($connection, $queues, $forwarded);
        }

        return $processes;
    }
}
