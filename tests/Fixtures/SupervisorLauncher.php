<?php declare(strict_types=1);

/**
 * Subprocess launcher for the `queue-insights:work` signal-forwarding
 * tests. Boots a Testbench-backed Laravel app, binds a stub
 * `WorkerProcessFactory` driven by JSON env spec, then invokes the
 * `queue-insights:work` artisan command.
 *
 * Test contract — env vars (all required):
 *   QI_LAUNCHER_SNAPSHOTS    JSON list<{connection, queue}> (passed
 *                            through to `queue-insights.snapshots`)
 *   QI_LAUNCHER_STUB_ENV     JSON map<connection, map<env_key,env_value>>
 *                            (forwarded as env to each stub child)
 *   QI_LAUNCHER_GRACE        Optional int — `work.shutdown_grace_seconds`
 *                            override; default 120 from config.
 *
 * Stdout/stderr are inherited from the parent test runner so the
 * supervisor's prefixed output is captured verbatim. The launcher's
 * exit code is the supervisor's exit code (`Artisan::call` return).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use Symfony\Component\Process\Process;

$snapshotsRaw = getenv('QI_LAUNCHER_SNAPSHOTS');
$stubEnvRaw = getenv('QI_LAUNCHER_STUB_ENV');
$graceRaw = getenv('QI_LAUNCHER_GRACE');

$snapshots = $snapshotsRaw !== false ? json_decode($snapshotsRaw, true) : [];
$stubEnv = $stubEnvRaw !== false ? json_decode($stubEnvRaw, true) : [];

// Package root — used to locate the StubWorker fixture below.
$packageRoot = dirname(__DIR__, 2);

// Testbench's bundled Laravel skeleton ships its own `bootstrap/cache`
// dir; pointing basePath at the package root would fail with
// "bootstrap/cache directory must be present and writable" because the
// package isn't an application. Using `@testbench` borrows the
// skeleton's writable cache + config tree, then we register the
// package's provider on top.
$basePath = $packageRoot . '/vendor/orchestra/testbench-core/laravel';

// CI fresh installs ship the testbench skeleton with `.env.example`
// only — no `.env`, so APP_KEY is unset and Testbench's bootstrap
// aborts before the supervisor's "Booting …" line. Locally `.env` is
// usually present (created on first run), masking the failure. Force
// a deterministic APP_KEY into the subprocess env so both shapes work.
$qiAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
putenv("APP_KEY={$qiAppKey}");
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $qiAppKey;

$app = TestbenchApplication::create(
    basePath: $basePath,
    resolvingCallback: null,
    options: [
        'enables_package_discoveries' => false,
    ],
);

// Disable the dashboard before the package boots. Without it, the
// provider's `registerDashboard` calls `Livewire::component()` which
// resolves `livewire.finder` — a binding only the Livewire service
// provider registers. CI fresh installs run with `enables_package_
// discoveries = false`, so Livewire's provider never loads and the
// boot aborts with "Class livewire.finder does not exist". The
// launcher exists to exercise the work command, not the dashboard.
config()->set('queue-insights.dashboard.enabled', false);

$app->register(QueueInsightsServiceProvider::class);

config()->set('queue-insights.snapshots', is_array($snapshots) ? $snapshots : []);
if ($graceRaw !== false && is_numeric($graceRaw)) {
    config()->set('queue-insights.work.shutdown_grace_seconds', (int) $graceRaw);
}

$factory = new readonly class ($stubEnv, $packageRoot) implements WorkerProcessFactory {
    public function __construct(
        private mixed $envSpec,
        private string $packageRoot,
    ) {}

    public function make(string $connection, array $queues, array $forwardedFlags): Process
    {
        $env = is_array($this->envSpec) && isset($this->envSpec[$connection]) && is_array($this->envSpec[$connection])
            ? $this->envSpec[$connection]
            : [];

        $process = new Process(
            [PHP_BINARY, $this->packageRoot . '/tests/Fixtures/StubWorker.php', $connection, ...$queues],
            null,
            $env,
        );
        $process->setTimeout(null);

        return $process;
    }
};

$app->instance(WorkerProcessFactory::class, $factory);

$kernel = $app->make(ConsoleKernel::class);

exit($kernel->call('queue-insights:work'));
