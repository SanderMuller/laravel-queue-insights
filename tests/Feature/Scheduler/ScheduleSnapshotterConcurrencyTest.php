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
        R::raw('hset', $tasksKey, $key, (string) json_encode([
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
    expect($runner)
        ->toBeFile();

    $prefix = 'qmrace:';
    $orderKey = $prefix . 'sched:tasks:order';
    $tasksKey = $prefix . 'sched:tasks';
    $taskCount = 12;
    // Two auto-registered package tasks (`queue-insights:snapshot` and
    // `queue-insights:schedule:sweep`) land in the snapshot when
    // `scheduler.enabled = true`. Their task_keys are identical across
    // workers (same command, same cron).
    $autoTaskCount = 2;
    $expectedTotal = $taskCount + $autoTaskCount;
    $workerCount = 4;
    $iterations = 2;

    $envOr = static fn (string $name, string $fallback): string => is_string($v = getenv($name)) && $v !== '' ? $v : $fallback;
    $childEnv = [
        'QI_SCHED_RACE_PREFIX' => $prefix,
        'QI_SCHED_RACE_TASKS' => (string) $taskCount,
        'REDIS_HOST' => $envOr('REDIS_HOST', '127.0.0.1'),
        'REDIS_PORT' => $envOr('REDIS_PORT', '6379'),
        'REDIS_DB' => $envOr('REDIS_DB', '15'),
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
        $order = is_array($orderRaw) ? array_values(array_filter($orderRaw, is_string(...))) : [];
        $uniqueCount = count(array_unique($order));

        // No duplicates.
        expect($order)
            ->toHaveCount($uniqueCount, sprintf(
                'iter %d: order list had %d entries, %d unique — duplicates present',
                $iter,
                count($order),
                $uniqueCount,
            ))
            // Exact count locks atomic single-writer semantics: if any
            // worker's writes leaked into another's snapshot, total
            // would exceed the per-worker task count. With the Lua
            // rewrite, only the last writer's snapshot survives so we
            // see exactly fixture + auto-registered entries.
            ->and($order)
            ->toHaveCount($expectedTotal, sprintf(
                'iter %d: expected exactly %d entries (one winning worker), got %d — possible mix from multiple writers',
                $iter,
                $expectedTotal,
                count($order),
            ));

        // Single-winner check — each fixture command embeds the
        // worker salt (`echo race-iter{N}-w{W}-{i}`), so every
        // non-auto-registered task in the final snapshot must share
        // the same `iter{N}-w{W}` prefix. A mixed final state from
        // multiple workers would surface as more than one salt.
        $salts = [];
        foreach ($order as $key) {
            $json = R::str('hget', $tasksKey, $key);
            if ($json === null) {
                continue;
            }
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            $command = is_array($decoded) && is_string($decoded['command'] ?? null) ? $decoded['command'] : '';
            if (preg_match('/race-(iter\d+-w\d+)-/', $command, $m) === 1) {
                $salts[$m[1]] = true;
            }
        }

        expect(array_keys($salts))
            ->toHaveCount(1, sprintf(
                'iter %d: fixture tasks came from %d different salts %s — atomic rewrite was not respected',
                $iter,
                count($salts),
                (string) json_encode(array_keys($salts)),
            ));
    }
});
