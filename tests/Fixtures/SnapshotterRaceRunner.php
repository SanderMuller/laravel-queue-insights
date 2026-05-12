<?php declare(strict_types=1);

/**
 * Subprocess runner for the ScheduleSnapshotter race test. Boots a
 * Testbench-backed Laravel app, registers a deterministic set of
 * scheduled tasks, then calls `ScheduleSnapshotter::rebuild()` once.
 *
 * Multiple instances of this runner are spawned in parallel by
 * `tests/Feature/Scheduler/ScheduleSnapshotterConcurrencyTest.php` —
 * each writes against the same Redis prefix to expose the
 * non-atomic DEL+RPUSH window in `rebuild()`.
 *
 * Env contract:
 *   QI_SCHED_RACE_PREFIX  Required. Key prefix shared with the parent
 *                         assertion so the order list lives at a
 *                         predictable path.
 *   QI_SCHED_RACE_TASKS   Required. Integer — number of synthetic
 *                         tasks to register. Each runner registers
 *                         the same set in the same order so the hash
 *                         short-circuit never fires.
 *   QI_SCHED_RACE_SALT    Optional. Per-iteration salt mixed into the
 *                         task command so a fresh run loop forces a
 *                         hash mismatch + full rewrite.
 *   REDIS_HOST/PORT/DB    Forwarded by the parent — runner inherits
 *                         the test process's Redis target.
 */

use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Scheduler\ScheduleSnapshotter;

require __DIR__ . '/../../vendor/autoload.php';

$prefix = getenv('QI_SCHED_RACE_PREFIX');
$tasksRaw = getenv('QI_SCHED_RACE_TASKS');
$salt = getenv('QI_SCHED_RACE_SALT');

if (! is_string($prefix) || $prefix === '' || ! is_string($tasksRaw) || ! is_numeric($tasksRaw)) {
    fwrite(STDERR, "missing QI_SCHED_RACE_PREFIX / QI_SCHED_RACE_TASKS\n");
    exit(2);
}

$taskCount = max(1, (int) $tasksRaw);
$saltSegment = is_string($salt) && $salt !== '' ? $salt : 'r0';

$packageRoot = dirname(__DIR__, 2);
$basePath = $packageRoot . '/vendor/orchestra/testbench-core/laravel';

$qiAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
putenv("APP_KEY={$qiAppKey}");
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $qiAppKey;

$app = TestbenchApplication::create(
    basePath: $basePath,
    resolvingCallback: null,
    options: ['enables_package_discoveries' => false],
);

config()->set('queue-insights.dashboard.enabled', false);
config()->set('queue-insights.prometheus.enabled', false);
config()->set('queue-insights.scheduler.enabled', true);
config()->set('queue-insights.key_prefix', $prefix);
config()->set('queue-insights.snapshots', []);

$host = getenv('REDIS_HOST');
$port = getenv('REDIS_PORT');
$db = getenv('REDIS_DB');
config()->set('database.redis.client', 'predis');
config()->set('database.redis.default', [
    'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
    'port' => is_string($port) && is_numeric($port) ? (int) $port : 6379,
    'database' => is_string($db) && is_numeric($db) ? (int) $db : 15,
]);

$app->register(QueueInsightsServiceProvider::class);

$schedule = $app->make(Schedule::class);
for ($i = 0; $i < $taskCount; ++$i) {
    // Synthetic exec tasks — `exec()` doesn't require an artisan
    // command class to exist, and the resulting mutexName is fully
    // disambiguated by the command string.
    $schedule->exec("echo race-{$saltSegment}-{$i}")->everyMinute();
}

(new ScheduleSnapshotter($schedule))->rebuild();

exit(0);
