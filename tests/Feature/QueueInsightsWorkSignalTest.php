<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use SanderMuller\QueueInsights\Console\WorkerOutputStreams;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl required for queue-insights:work signal tests');
    }
});

/**
 * Phase 3 — `validateWork` config typecheck.
 */
it('validateWork accepts a positive int default', function (): void {
    // `validateWork` returns void; the assertion is the absence of a
    // thrown exception. Pest fails the test if any of these throw, so
    // reaching the trailing `expect(true)` is itself the contract.
    ConfigValidator::validateWork([]);
    ConfigValidator::validateWork(['shutdown_grace_seconds' => 1]);
    ConfigValidator::validateWork(['shutdown_grace_seconds' => 120]);
    expect(true)->toBeTrue();
});

it('validateWork throws on a non-int shutdown_grace_seconds', function (): void {
    ConfigValidator::validateWork(['shutdown_grace_seconds' => '120']);
})->throws(QueueInsightsConfigException::class, 'shutdown_grace_seconds must be a positive integer');

it('validateWork throws on a zero or negative shutdown_grace_seconds', function (): void {
    ConfigValidator::validateWork(['shutdown_grace_seconds' => 0]);
})->throws(QueueInsightsConfigException::class);

it('validateWork throws on a non-int float shutdown_grace_seconds', function (): void {
    ConfigValidator::validateWork(['shutdown_grace_seconds' => 1.5]);
})->throws(QueueInsightsConfigException::class);

/**
 * Phase 3 — signal handler + async-signal state restoration.
 *
 * Octane / Pest / any long-lived host that runs `queue-insights:work`
 * in-process must not inherit our SIGTERM/SIGINT/SIGQUIT handlers
 * after `handle()` returns. Otherwise the closures (with stale
 * by-ref captures of `$exits` / `$processes`) would intercept later
 * signals incorrectly. The supervisor wraps its handler installation
 * in try/finally so the previous handlers + async-signal state are
 * restored on every exit path.
 */
it('restores signal handlers and async-signal state after handle()', function (): void {
    // Snapshot current state (Pest typically runs with default
    // handlers + async signals disabled at this point).
    $beforeAsync = pcntl_async_signals();
    $beforeTerm = pcntl_signal_get_handler(SIGTERM);
    $beforeInt = pcntl_signal_get_handler(SIGINT);
    $beforeQuit = pcntl_signal_get_handler(SIGQUIT);

    $factory = new RecordingWorkerFactory();
    $this->app->instance(WorkerProcessFactory::class, $factory);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0)
        ->and(pcntl_async_signals())
        ->toBe($beforeAsync)
        ->and(pcntl_signal_get_handler(SIGTERM))
        ->toBe($beforeTerm)
        ->and(pcntl_signal_get_handler(SIGINT))
        ->toBe($beforeInt)
        ->and(pcntl_signal_get_handler(SIGQUIT))
        ->toBe($beforeQuit);
});

/**
 * Phase 3 — grace expiry → SIGKILL escalation.
 *
 * In-process feature test: parent supervisor + a stub child that ignores
 * `SIGTERM` via `SIG_IGN`. With `shutdown_grace_seconds = 1`, the
 * supervisor's TERM gets ignored, the grace window expires, and the
 * child receives `SIGKILL` (which it cannot ignore). The first
 * non-zero sibling exit triggers the teardown so the path doesn't
 * depend on signal delivery to the supervisor itself.
 */
it('escalates to SIGKILL when a child ignores SIGTERM past the grace window', function (): void {
    $factory = new class implements WorkerProcessFactory {
        /** @var array<string, array<string, string>> */
        public array $envByConnection = [
            'sqs' => ['STUB_EXIT' => '7'],
            // Survivor: ignore TERM, sleep 30s — grace expires in 1s,
            // SIGKILL ends it.
            'redis' => ['STUB_IGNORE_TERM' => '1', 'STUB_SLEEP' => '30'],
        ];

        public function make(string $connection, array $queues, array $forwardedFlags): Process
        {
            $env = $this->envByConnection[$connection] ?? [];
            $process = new Process(
                [PHP_BINARY, dirname(__DIR__) . '/Fixtures/StubWorker.php', $connection, ...$queues],
                null,
                $env,
            );
            $process->setTimeout(null);

            return $process;
        }
    };

    $streams = new class implements WorkerOutputStreams {
        /** @var resource */
        public mixed $out;

        /** @var resource */
        public mixed $err;

        public function __construct()
        {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');

            if ($out === false || $err === false) {
                throw new RuntimeException('failed to open php://memory streams for the grace-expiry test');
            }

            $this->out = $out;
            $this->err = $err;
        }

        public function stdout(): mixed
        {
            return $this->out;
        }

        public function stderr(): mixed
        {
            return $this->err;
        }

        public function readStderr(): string
        {
            rewind($this->err);

            $contents = stream_get_contents($this->err);

            return $contents === false ? '' : $contents;
        }
    };

    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);
    config()->set('queue-insights.work.shutdown_grace_seconds', 1);

    $start = microtime(true);
    $exit = Artisan::call('queue-insights:work');
    $duration = microtime(true) - $start;

    expect($exit)->toBe(7);
    // Grace = 1s + ~100ms poll tick + SIGKILL reap. Generous upper bound.
    expect($duration)->toBeLessThan(5.0);

    $stderr = $streams->readStderr();
    expect($stderr)->toContain('grace window (1s) expired')
        ->toContain('redis');
});

/**
 * Phase 3 — external signal forwarding via supervisor-as-subprocess.
 *
 * Spec §4.2: in-process signal handling is unsafe under PHPUnit's
 * process model. Launch the supervisor as its own subprocess via
 * `proc_open`, send SIGTERM to its PID, assert children received TERM
 * (their stdout shows `caught:SIGTERM`) and the supervisor exit code
 * follows Bash convention (128 + signum).
 */
it('forwards SIGTERM to live children when the supervisor receives one', function (): void {
    $launcher = dirname(__DIR__) . '/Fixtures/SupervisorLauncher.php';
    if (! is_file($launcher)) {
        $this->markTestSkipped('SupervisorLauncher.php fixture missing');
    }

    $env = [
        'QI_LAUNCHER_SNAPSHOTS' => json_encode([
            ['connection' => 'sqs', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'mail'],
        ]),
        'QI_LAUNCHER_STUB_ENV' => json_encode([
            'sqs' => ['STUB_TRAP' => '1', 'STUB_SLEEP' => '30'],
            'redis' => ['STUB_TRAP' => '1', 'STUB_SLEEP' => '30'],
        ]),
        'QI_LAUNCHER_GRACE' => '5',
        'PATH' => is_string($pathEnv = getenv('PATH')) && $pathEnv !== '' ? $pathEnv : '/usr/bin:/bin',
        'HOME' => is_string($homeEnv = getenv('HOME')) && $homeEnv !== '' ? $homeEnv : '/tmp',
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open(
        [PHP_BINARY, $launcher],
        $descriptors,
        $pipes,
        null,
        $env,
    );

    if (! is_resource($proc)) {
        $this->fail('proc_open failed to launch the supervisor subprocess');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';

    // Give the supervisor time to spawn children and install signal
    // handlers — without this, SIGTERM can land before pcntl_signal
    // runs and the parent dies without forwarding.
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        Sleep::usleep(100_000);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (str_contains($stdout, 'Booting 2 connection(s)')) {
            break;
        }
    }

    $status = proc_get_status($proc);
    expect($status['running'])->toBeTrue();

    posix_kill($status['pid'], SIGTERM);

    // Drain stdout while supervisor tears down. Bound at 10s — ample
    // for the 5s grace + child SIGTERM handlers + reaping.
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        Sleep::usleep(100_000);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (! $status['running']) {
            break;
        }
    }

    // Final drain.
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = $status['running'] ? null : $status['exitcode'];
    if ($status['running']) {
        proc_terminate($proc, SIGKILL);
        proc_close($proc);
        $this->fail('Supervisor did not exit within 10s of SIGTERM');
    }

    proc_close($proc);

    // 128 + 15 (SIGTERM) when the parent's signalReceived path wins.
    expect($exitCode)->toBe(143);
    expect($stdout)->toContain('[sqs] caught:SIGTERM')
        ->toContain('[redis] caught:SIGTERM');
});

/**
 * Phase 3 — grace timer is idempotent under repeated signals.
 *
 * The signal handler's `if ($signalReceived !== null) return` guard at
 * `QueueInsightsWorkCommand.php` is the contract. An operator pressing
 * Ctrl-C twice (or systemd retrying SIGTERM during a slow stop) must
 * NOT reset `$teardownStartedAt` — otherwise the SIGKILL escalation
 * window keeps sliding and an unresponsive child is never killed.
 *
 * Test shape: launch supervisor with `STUB_IGNORE_TERM=1` children
 * sleeping 30s and `shutdown_grace_seconds=5`. Send SIGTERM at t=0,
 * second SIGTERM at t≈2s (mid-grace). Verify supervisor exits within
 * ~7s of the FIRST signal (5s grace + reap headroom), not 10s+ which
 * would indicate the timer reset.
 */
it('does not reset the grace timer when SIGTERM is sent twice', function (): void {
    $launcher = dirname(__DIR__) . '/Fixtures/SupervisorLauncher.php';
    if (! is_file($launcher)) {
        $this->markTestSkipped('SupervisorLauncher.php fixture missing');
    }

    $env = [
        'QI_LAUNCHER_SNAPSHOTS' => json_encode([
            ['connection' => 'sqs', 'queue' => 'default'],
        ]),
        'QI_LAUNCHER_STUB_ENV' => json_encode([
            'sqs' => ['STUB_IGNORE_TERM' => '1', 'STUB_SLEEP' => '30'],
        ]),
        'QI_LAUNCHER_GRACE' => '5',
        'PATH' => is_string($pathEnv = getenv('PATH')) && $pathEnv !== '' ? $pathEnv : '/usr/bin:/bin',
        'HOME' => is_string($homeEnv = getenv('HOME')) && $homeEnv !== '' ? $homeEnv : '/tmp',
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open([PHP_BINARY, $launcher], $descriptors, $pipes, null, $env);

    if (! is_resource($proc)) {
        $this->fail('proc_open failed to launch the supervisor subprocess');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';

    // Wait until the supervisor logs "Booting 1 connection(s)" — proves
    // pcntl_signal() ran and children started before we send any signal.
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        Sleep::usleep(100_000);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if (str_contains($stdout, 'Booting 1 connection(s)')) {
            break;
        }
    }

    $status = proc_get_status($proc);
    expect($status['running'])->toBeTrue();
    $supervisorPid = $status['pid'];

    // First SIGTERM — supervisor forwards to child (which ignores it),
    // arms `$signalReceived` and starts the grace timer.
    $sigSentAt = microtime(true);
    posix_kill($supervisorPid, SIGTERM);

    // Wait ~2s, send a SECOND SIGTERM mid-grace. If the handler isn't
    // idempotent, this resets `$teardownStartedAt` and the child won't
    // be SIGKILLed until ~7s after the first signal.
    Sleep::sleep(2);
    posix_kill($supervisorPid, SIGTERM);

    // Bound the wait at 10s after the FIRST signal. Idempotent handler:
    // exit at ~5s (grace) + reap. Resetting handler: exit at ~7s+ (2s
    // wait + 5s grace from the second signal).
    $deadline = $sigSentAt + 10.0;
    while (microtime(true) < $deadline) {
        Sleep::usleep(100_000);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (! $status['running']) {
            break;
        }
    }

    $exitElapsed = microtime(true) - $sigSentAt;

    // Final drain.
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = $status['running'] ? null : $status['exitcode'];
    if ($status['running']) {
        proc_terminate($proc, SIGKILL);
        proc_close($proc);
        $this->fail('Supervisor did not exit within 10s of first SIGTERM — grace timer likely reset');
    }

    proc_close($proc);

    // Idempotent handler: exit happens within grace (5s) + reap headroom
    // (~1.5s). Anything ≥ 7s would indicate the timer was reset by the
    // second SIGTERM. 6.5s upper bound is generous on a busy CI runner
    // while still failing fast on the regression.
    expect($exitElapsed)
        ->toBeLessThan(6.5)
        ->and($stderr)
        ->toContain('grace window (5s) expired');

    // Child ignored SIGTERM so SIGKILL is the only way it died. Symfony
    // Process reports SIGKILL'd children as exit code 137 (128 + 9),
    // which `reapExitedChildren` captures into `$firstFailure` ahead of
    // the parent's signal-induced 143 — so the supervisor's exit code
    // is the child's failure, per `resolveExitCode()`'s precedence.
    expect($exitCode)->toBe(137);
});
