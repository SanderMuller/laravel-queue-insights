<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmrace:');
    config()->set('queue-insights.scheduler.enabled', true);
});

/**
 * Read-side guard: a polluted `sched:tasks:order` (the post-race shape
 * we observed on staging — 157 entries pointing at ~20 unique keys)
 * must not produce duplicate rows in `ScheduleReader::tasks()`. The
 * snapshot hash + task hash stay clean; only the order list grows.
 */
it('ScheduleReader::tasks() returns each task once even when the order list has duplicates', function (): void {
    $prefix = 'qmrace:';
    $tasksKey = $prefix . 'sched:tasks';
    $orderKey = $prefix . 'sched:tasks:order';

    // Seed three unique task summaries.
    $unique = ['task-a', 'task-b', 'task-c'];
    foreach ($unique as $i => $key) {
        R::raw('hset', $tasksKey, $key, json_encode([
            'description' => null,
            'command' => "echo {$key}",
            'expression' => '* * * * *',
            'timezone' => null,
            'runInBackground' => false,
            'onOneServer' => false,
            'evenInMaintenanceMode' => false,
            'withoutOverlapping' => false,
            'mutexName' => "framework/schedule-{$i}",
            'type' => 'exec',
        ]));
    }

    // Pollute the order list — simulate 4 concurrent rebuilds racing
    // through the non-atomic DEL+RPUSH window.
    $polluted = [];
    for ($rebuild = 0; $rebuild < 4; ++$rebuild) {
        foreach ($unique as $key) {
            $polluted[] = $key;
        }
    }
    foreach ($polluted as $key) {
        R::raw('rpush', $orderKey, $key);
    }

    // Sanity — the polluted state has 12 entries, 3 unique.
    $orderRaw = R::raw('lrange', $orderKey, 0, -1);
    expect(is_array($orderRaw) ? count($orderRaw) : 0)->toBe(12);

    $tasks = resolve(ScheduleReader::class)->tasks();

    $taskKeys = array_map(static fn (array $row): string => $row['task_key'], $tasks);
    expect($taskKeys)
        ->toHaveCount(3)
        ->toBe($unique);
});

/**
 * Write-side guard: multiple concurrent `rebuild()` invocations must
 * leave the order list with no duplicate entries.
 *
 * Spawns N subprocess runners that each register the same fixture
 * schedule and call `ScheduleSnapshotter::rebuild()` in parallel.
 * Repeats over a few iterations to reliably hit the interleave
 * window between the two `DEL`s and the per-task `RPUSH`es.
 *
 * Today: FAILS — two concurrent rebuilds can each `DEL` after the
 * other's `RPUSH`es have started, leaving duplicates in the list.
 * After atomicity fix: PASSES deterministically.
 */
it('concurrent ScheduleSnapshotter::rebuild() never produces duplicate order entries', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open required');
    }

    $packageRoot = dirname(__DIR__, 3);
    $runner = $packageRoot . '/tests/Fixtures/SnapshotterRaceRunner.php';
    expect(is_file($runner))->toBeTrue();

    $prefix = 'qmrace:';
    $orderKey = $prefix . 'sched:tasks:order';
    $taskCount = 12;
    $workerCount = 4;
    $iterations = 4;

    $childEnv = [
        'QI_SCHED_RACE_PREFIX' => $prefix,
        'QI_SCHED_RACE_TASKS' => (string) $taskCount,
        'REDIS_HOST' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'REDIS_PORT' => getenv('REDIS_PORT') ?: '6379',
        'REDIS_DB' => getenv('REDIS_DB') ?: '15',
    ];

    for ($iter = 0; $iter < $iterations; ++$iter) {
        RedisAvailability::flush();

        $procs = [];
        for ($w = 0; $w < $workerCount; ++$w) {
            // Per-worker salt so each runner computes a different
            // snapshot hash — all four bypass the idempotent
            // short-circuit and race through DEL+RPUSH in parallel.
            // This mirrors prod (FPM + queue workers boot with
            // different task sets when deploys overlap) and
            // maximises the chance of hitting the race window per
            // iteration.
            $proc = new Process(
                [PHP_BINARY, $runner],
                $packageRoot,
                $childEnv + ['QI_SCHED_RACE_SALT' => "iter{$iter}-w{$w}"],
            );
            $proc->setTimeout(60);
            $proc->start();
            $procs[] = $proc;
        }

        foreach ($procs as $proc) {
            $proc->wait();
            if ($proc->getExitCode() !== 0) {
                $this->fail("runner failed: stdout={$proc->getOutput()} stderr={$proc->getErrorOutput()}");
            }
        }

        $orderRaw = R::raw('lrange', $orderKey, 0, -1);
        $order = is_array($orderRaw) ? array_values(array_filter($orderRaw, 'is_string')) : [];

        $unique = array_values(array_unique($order));

        expect($order)
            ->toHaveCount(count($unique), sprintf(
                'iter %d: order list had %d entries, %d unique — duplicates present',
                $iter,
                count($order),
                count($unique),
            ))
            ->and(count($order))
            ->toBeGreaterThanOrEqual($taskCount);
    }
});
