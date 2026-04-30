<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
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
    expect(ConfigValidator::validateWork([]))->toBeNull();
    expect(ConfigValidator::validateWork(['shutdown_grace_seconds' => 1]))->toBeNull();
    expect(ConfigValidator::validateWork(['shutdown_grace_seconds' => 120]))->toBeNull();
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
            $this->out = fopen('php://memory', 'w+') ?: throw new RuntimeException('memory stream open failed');
            $this->err = fopen('php://memory', 'w+') ?: throw new RuntimeException('memory stream open failed');
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

            return stream_get_contents($this->err) ?: '';
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
    expect($stderr)->toContain('grace window (1s) expired');
    expect($stderr)->toContain('redis');
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
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
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

    expect($proc)->toBeResource();

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
        usleep(100_000);
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
        usleep(100_000);
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
    expect($stdout)->toContain('[sqs] caught:SIGTERM');
    expect($stdout)->toContain('[redis] caught:SIGTERM');
});
