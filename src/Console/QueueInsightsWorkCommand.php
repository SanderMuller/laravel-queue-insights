<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use SanderMuller\QueueInsights\Support\Config;
use Symfony\Component\Process\Process;

/**
 * Multi-connection queue worker supervisor.
 *
 * Reads `queue-insights.snapshots`, groups entries by connection, and
 * spawns one `queue:work` subprocess per connection with `--queue=`
 * carrying the comma-joined queue list (Laravel's built-in priority
 * order). This command is a thin parent supervisor — it owns argv
 * assembly + signal forwarding + exit-code propagation. Restart-on-crash
 * and other liveness concerns belong to the host's process manager.
 *
 * Phase 1 ships argv assembly + boot-time refusals. Phase 2 wires the
 * spawn loop. Phase 3 adds signal forwarding + grace shutdown.
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
            $map = array_intersect_key($map, array_flip($filter));

            if ($map === []) {
                $this->components->error(sprintf(
                    'queue-insights:work: --connection=%s matched no monitored connections. Configured: %s',
                    implode(',', $filter),
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
        $this->startProcesses($processes, $prefixer);

        /** @var array<string, int> $exits */
        $exits = [];
        $firstFailure = null;
        $teardownIssued = false;
        $teardownStartedAt = null;
        $signalReceived = null;
        $killEscalated = false;

        $this->installSignalHandlers($processes, $exits, $signalReceived, $teardownIssued, $teardownStartedAt);

        $graceSeconds = Config::int('work.shutdown_grace_seconds', 120);

        while (count($exits) < count($processes)) {
            $this->reapExitedChildren($processes, $exits, $prefixer, $firstFailure);

            if ($firstFailure !== null && ! $teardownIssued) {
                $teardownIssued = true;
                $teardownStartedAt = microtime(true);
                $this->terminateLiveChildren($processes, $exits, SIGTERM);
            }

            if ($teardownIssued && ! $killEscalated && $teardownStartedAt !== null && microtime(true) - $teardownStartedAt > $graceSeconds) {
                $this->escalateKill($processes, $exits, $graceSeconds);
                $killEscalated = true;
            }

            if (count($exits) < count($processes)) {
                Sleep::usleep(100_000);
            }
        }

        return $this->resolveExitCode($firstFailure, $signalReceived);
    }

    /**
     * @param  array<string, Process>  $processes
     */
    private function startProcesses(array $processes, WorkerOutputPrefixer $prefixer): void
    {
        foreach ($processes as $connection => $process) {
            $process->start(function (string $type, string $buffer) use ($connection, $prefixer): void {
                $prefixer->append($connection, $type, $buffer);
            });
        }
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

            if ($process->isRunning()) {
                $process->signal($signal);
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

            if ($process->isRunning()) {
                $process->signal(SIGKILL);
                $killed[] = $connection;
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

            if (! is_string($queue)) {
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

        return $out === [] ? null : $out;
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
